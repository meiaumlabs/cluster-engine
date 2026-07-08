<?php
/**
 * CE61_Analyzer — similarity graph, clustering, cluster strength, diagnostics.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CE61_Analyzer {

	/**
	 * Load all vectors from the index, TF-IDF weighted and L2-normalized.
	 * Cached in a static for the duration of the request.
	 */
	public static function load_vectors() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT post_id, vector FROM {$wpdb->prefix}ce_index", ARRAY_A );
		$docs = array();
		$df   = array();
		foreach ( $rows as $r ) {
			$tf = json_decode( $r['vector'], true );
			if ( ! is_array( $tf ) || ! $tf ) {
				continue;
			}
			$docs[ (int) $r['post_id'] ] = $tf;
			foreach ( $tf as $term => $w ) {
				$df[ $term ] = ( isset( $df[ $term ] ) ? $df[ $term ] : 0 ) + 1;
			}
		}
		$n       = max( 1, count( $docs ) );
		$vectors = array();
		foreach ( $docs as $pid => $tf ) {
			$vec  = array();
			$norm = 0.0;
			foreach ( $tf as $term => $w ) {
				$idf = log( ( $n + 1 ) / ( $df[ $term ] + 1 ) ) + 1;
				$val = $w * $idf;
				$vec[ $term ] = $val;
				$norm        += $val * $val;
			}
			$norm = sqrt( $norm );
			if ( $norm > 0 ) {
				foreach ( $vec as $t => $v ) {
					$vec[ $t ] = $v / $norm;
				}
			}
			$vectors[ $pid ] = $vec;
		}
		$cache = $vectors;
		return $vectors;
	}

	/**
	 * Cosine similarity of two normalized sparse vectors.
	 */
	public static function cosine( $a, $b ) {
		if ( count( $a ) > count( $b ) ) {
			list( $a, $b ) = array( $b, $a );
		}
		$dot = 0.0;
		foreach ( $a as $t => $v ) {
			if ( isset( $b[ $t ] ) ) {
				$dot += $v * $b[ $t ];
			}
		}
		return $dot;
	}

	/**
	 * Compute pairwise relations for a batch of source posts against the whole corpus.
	 * Stores pairs with similarity above floor, plus every linked pair.
	 */
	public static function relations_batch( $offset, $size = 15 ) {
		global $wpdb;
		$vectors = self::load_vectors();
		$ids     = array_keys( $vectors );
		sort( $ids );
		$total = count( $ids );
		$slice = array_slice( $ids, $offset, $size );

		$settings = get_option( 'ce61_settings', array() );
		$floor    = 0.06;

		// Link map for the whole corpus.
		$links = array();
		$rows  = $wpdb->get_results( "SELECT post_id, links_out FROM {$wpdb->prefix}ce_index", ARRAY_A );
		foreach ( $rows as $r ) {
			$l = json_decode( $r['links_out'], true );
			$links[ (int) $r['post_id'] ] = is_array( $l ) ? array_map( 'intval', array_keys( $l ) ) : array();
		}

		foreach ( $slice as $a ) {
			foreach ( $ids as $b ) {
				if ( $b <= $a ) {
					continue;
				}
				$sim     = self::cosine( $vectors[ $a ], $vectors[ $b ] );
				$link_ab = in_array( $b, $links[ $a ], true ) ? 1 : 0;
				$link_ba = in_array( $a, $links[ $b ], true ) ? 1 : 0;
				if ( $sim < $floor && ! $link_ab && ! $link_ba ) {
					continue;
				}
				$wpdb->replace(
					$wpdb->prefix . 'ce_relations',
					array(
						'post_a'     => $a,
						'post_b'     => $b,
						'similarity' => round( $sim, 4 ),
						'link_ab'    => $link_ab,
						'link_ba'    => $link_ba,
					),
					array( '%d', '%d', '%f', '%d', '%d' )
				);
			}
		}
		return array(
			'done'  => min( $offset + $size, $total ),
			'total' => $total,
		);
	}

	/**
	 * Greedy centroid clustering over the TF-IDF vectors.
	 */
	public static function cluster() {
		global $wpdb;
		$vectors = self::load_vectors();
		if ( ! $vectors ) {
			return 0;
		}

		// Order posts by word count (bigger first — good centroid seeds).
		$order = $wpdb->get_col( "SELECT post_id FROM {$wpdb->prefix}ce_index ORDER BY word_count DESC" );

		$threshold = 0.16;
		$clusters  = array(); // idx => [ 'members' => [], 'centroid' => [] ]

		foreach ( $order as $pid ) {
			$pid = (int) $pid;
			if ( ! isset( $vectors[ $pid ] ) ) {
				continue;
			}
			$best = -1;
			$bsim = 0;
			foreach ( $clusters as $ci => $c ) {
				$sim = self::cosine( $vectors[ $pid ], $c['centroid'] );
				if ( $sim > $bsim ) {
					$bsim = $sim;
					$best = $ci;
				}
			}
			if ( $best >= 0 && $bsim >= $threshold ) {
				$clusters[ $best ]['members'][] = $pid;
				// Update centroid (running mean on shared sparse space, keep top 80 terms).
				$cent = $clusters[ $best ]['centroid'];
				foreach ( $vectors[ $pid ] as $t => $v ) {
					$cent[ $t ] = ( isset( $cent[ $t ] ) ? $cent[ $t ] : 0 ) + $v;
				}
				arsort( $cent );
				$clusters[ $best ]['centroid'] = array_slice( $cent, 0, 80, true );
			} else {
				$clusters[] = array(
					'members'  => array( $pid ),
					'centroid' => $vectors[ $pid ],
				);
			}
		}

		// Persist: clusters with a single member become "orphans" (cluster 0).
		// Clusters criados manualmente (is_custom = 1) são preservados no rescan.
		$custom_ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}ce_clusters WHERE is_custom = 1" ) );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}ce_clusters WHERE is_custom = 0" );
		if ( $custom_ids ) {
			$keep = implode( ',', $custom_ids );
			$wpdb->query( "UPDATE {$wpdb->prefix}ce_index SET cluster_id = 0, is_pillar = 0 WHERE cluster_id NOT IN ($keep)" ); // phpcs:ignore
		} else {
			$wpdb->query( "UPDATE {$wpdb->prefix}ce_index SET cluster_id = 0, is_pillar = 0" );
		}

		$count = 0;
		foreach ( $clusters as $c ) {
			if ( count( $c['members'] ) < 2 ) {
				continue;
			}
			arsort( $c['centroid'] );
			$top = array_slice( array_keys( $c['centroid'] ), 0, 6 );
			// Prefer bigrams for the cluster name.
			$name = '';
			foreach ( $top as $t ) {
				if ( false !== strpos( $t, ' ' ) ) {
					$name = $t;
					break;
				}
			}
			if ( ! $name && $top ) {
				$name = $top[0];
			}
			$wpdb->insert(
				$wpdb->prefix . 'ce_clusters',
				array(
					'name'       => ucfirst( mb_substr( $name, 0, 191 ) ),
					'post_count' => count( $c['members'] ),
					'top_terms'  => wp_json_encode( $top, JSON_UNESCAPED_UNICODE ),
				),
				array( '%s', '%d', '%s' )
			);
			$cid = (int) $wpdb->insert_id;
			$count++;

			// Pillar = member with the highest centrality (sum of similarity to siblings) weighted by size.
			$best_p = 0;
			$best_s = -1;
			$vecs   = self::load_vectors();
			foreach ( $c['members'] as $m ) {
				$s = 0;
				foreach ( $c['members'] as $m2 ) {
					if ( $m2 !== $m && isset( $vecs[ $m ], $vecs[ $m2 ] ) ) {
						$s += self::cosine( $vecs[ $m ], $vecs[ $m2 ] );
					}
				}
				$wc = (int) $wpdb->get_var( $wpdb->prepare( "SELECT word_count FROM {$wpdb->prefix}ce_index WHERE post_id = %d", $m ) );
				$s += min( 1.5, $wc / 2000 );
				if ( $s > $best_s ) {
					$best_s = $s;
					$best_p = $m;
				}
			}

			$in = implode( ',', array_map( 'intval', $c['members'] ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}ce_index SET cluster_id = %d WHERE post_id IN ($in)", $cid ) ); // phpcs:ignore
			$wpdb->update( $wpdb->prefix . 'ce_index', array( 'is_pillar' => 1 ), array( 'post_id' => $best_p ) );
			$wpdb->update( $wpdb->prefix . 'ce_clusters', array( 'pillar_id' => $best_p ), array( 'id' => $cid ) );
		}
		return $count;
	}

	/**
	 * Update inbound-link counters and per-post issues, then score clusters.
	 */
	public static function finalize() {
		global $wpdb;
		$settings = get_option( 'ce61_settings', array() );
		$sim_link = isset( $settings['sim_link'] ) ? (float) $settings['sim_link'] : 0.22;
		$sim_weak = isset( $settings['sim_weak'] ) ? (float) $settings['sim_weak'] : 0.08;
		$min_w    = isset( $settings['min_words'] ) ? (int) $settings['min_words'] : 300;
		$stale_m  = isset( $settings['stale_months'] ) ? (int) $settings['stale_months'] : 18;

		// Inbound counts.
		$wpdb->query( "UPDATE {$wpdb->prefix}ce_index SET inbound = 0" );
		$rows = $wpdb->get_results( "SELECT post_id, links_out FROM {$wpdb->prefix}ce_index", ARRAY_A );
		$inb  = array();
		foreach ( $rows as $r ) {
			$l = json_decode( $r['links_out'], true );
			if ( is_array( $l ) ) {
				foreach ( array_keys( $l ) as $t ) {
					$t = (int) $t;
					$inb[ $t ] = ( isset( $inb[ $t ] ) ? $inb[ $t ] : 0 ) + 1;
				}
			}
		}
		foreach ( $inb as $pid => $c ) {
			$wpdb->update( $wpdb->prefix . 'ce_index', array( 'inbound' => $c ), array( 'post_id' => $pid ), array( '%d' ), array( '%d' ) );
		}

		// Per-post SEO/AEO issues and scores.
		$posts = $wpdb->get_results( "SELECT post_id, word_count, inbound, cluster_id FROM {$wpdb->prefix}ce_index", ARRAY_A );
		foreach ( $posts as $p ) {
			$pid    = (int) $p['post_id'];
			$post   = get_post( $pid );
			$issues = array();
			$seo    = 100;
			$aeo    = 100;

			if ( ! $post ) {
				continue;
			}
			$content = (string) $post->post_content;

			$title = CE61_SEO::get_meta_title( $pid );
			$desc  = CE61_SEO::get_meta_desc( $pid );
			$fk    = CE61_SEO::get_focus_keyword( $pid );

			if ( ! $title ) { $issues[] = 'no_meta_title'; $seo -= 15; }
			if ( ! $desc )  { $issues[] = 'no_meta_desc';  $seo -= 15; }
			elseif ( mb_strlen( $desc ) < 70 ) { $issues[] = 'short_meta_desc'; $seo -= 8; }
			if ( ! $fk )    { $issues[] = 'no_focus_keyword'; $seo -= 10; }

			if ( (int) $p['word_count'] < $min_w ) { $issues[] = 'thin_content'; $seo -= 20; }
			if ( 0 === (int) $p['inbound'] ) { $issues[] = 'orphan_no_inbound'; $seo -= 15; }
			if ( 0 === (int) $p['cluster_id'] ) { $issues[] = 'no_cluster'; $seo -= 10; }

			$months = ( time() - strtotime( $post->post_modified ) ) / MONTH_IN_SECONDS;
			if ( $months > $stale_m ) { $issues[] = 'stale_content'; $seo -= 10; }

			// AEO/GEO checks.
			$first = '';
			if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $content, $m ) ) {
				$first = trim( wp_strip_all_tags( $m[1] ) );
			}
			$len_first = mb_strlen( $first );
			if ( $len_first < 120 || $len_first > 480 ) { $issues[] = 'no_answer_capsule'; $aeo -= 25; }

			$q_headings = preg_match_all( '/<h[2-3][^>]*>[^<]*\?/iu', $content );
			if ( ! $q_headings ) { $issues[] = 'no_question_headings'; $aeo -= 15; }

			if ( false === stripos( $content, 'faqpage' ) && ! preg_match( '/perguntas\s+frequentes|faq/iu', $content ) ) {
				$issues[] = 'no_faq'; $aeo -= 15;
			}
			if ( ! preg_match( '/<a\s[^>]*href=["\']https?:\/\//i', preg_replace( '/href=["\']' . preg_quote( home_url(), '/' ) . '/i', '', $content ) ) ) {
				$issues[] = 'no_external_sources'; $aeo -= 15;
			}
			$author_bio = get_the_author_meta( 'description', $post->post_author );
			if ( ! $author_bio ) { $issues[] = 'no_author_bio'; $aeo -= 15; }

			// Headings in Title Case or ALL CAPS (PT-BR prefers sentence case).
			if ( preg_match_all( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $content, $hm ) ) {
				foreach ( $hm[1] as $h ) {
					if ( self::is_over_capitalized( trim( wp_strip_all_tags( $h ) ) ) ) {
						$issues[] = 'capitalized_headings';
						$aeo -= 5;
						break;
					}
				}
			}

			if ( preg_match_all( '/<img\s[^>]*>/i', $content, $imgs ) ) {
				$noalt = 0;
				foreach ( $imgs[0] as $tag ) {
					if ( ! preg_match( '/alt=["\'][^"\']+["\']/i', $tag ) ) {
						$noalt++;
					}
				}
				if ( $noalt ) { $issues[] = 'images_no_alt'; $seo -= 5; }
			}

			$wpdb->update(
				$wpdb->prefix . 'ce_index',
				array(
					'seo_score' => max( 0, $seo ),
					'aeo_score' => max( 0, $aeo ),
					'issues'    => wp_json_encode( $issues ),
				),
				array( 'post_id' => $pid )
			);
		}

		// Cluster scores.
		$clusters = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ce_clusters", ARRAY_A );
		foreach ( $clusters as $c ) {
			$cid     = (int) $c['id'];
			$members = $wpdb->get_results( $wpdb->prepare(
				"SELECT post_id, is_pillar, inbound, links_out, seo_score, aeo_score, word_count, issues FROM {$wpdb->prefix}ce_index WHERE cluster_id = %d", $cid
			), ARRAY_A );
			$n = count( $members );
			if ( ! $n ) {
				continue;
			}
			$pillar = (int) $c['pillar_id'];

			// Architecture: satellites linking to pillar and pillar linking back.
			$sat_to_pillar = 0;
			$pillar_out    = 0;
			$avg_seo = 0; $avg_aeo = 0; $orphans = 0; $thin = 0;
			foreach ( $members as $m ) {
				$links = json_decode( $m['links_out'], true );
				$links = is_array( $links ) ? array_map( 'intval', array_keys( $links ) ) : array();
				if ( (int) $m['post_id'] !== $pillar && in_array( $pillar, $links, true ) ) {
					$sat_to_pillar++;
				}
				if ( (int) $m['post_id'] === $pillar ) {
					foreach ( $links as $t ) {
						foreach ( $members as $m2 ) {
							if ( (int) $m2['post_id'] === $t ) { $pillar_out++; break; }
						}
					}
				}
				$avg_seo += (int) $m['seo_score'];
				$avg_aeo += (int) $m['aeo_score'];
				if ( 0 === (int) $m['inbound'] ) { $orphans++; }
				if ( (int) $m['word_count'] < $min_w ) { $thin++; }
			}
			$sats = max( 1, $n - 1 );

			$coverage = min( 100, ( $n / 8 ) * 100 );                                  // 8+ posts = full coverage proxy.
			$arch     = ( ( $sat_to_pillar / $sats ) * 60 ) + ( min( 1, $pillar_out / $sats ) * 40 );
			$aeo      = $avg_aeo / $n;
			$eeat     = $avg_seo / $n;
			$hygiene  = max( 0, 100 - ( ( $orphans + $thin ) / $n ) * 100 );

			$score = (int) round( $coverage * 0.30 + $arch * 0.25 + $aeo * 0.20 + $eeat * 0.15 + $hygiene * 0.10 );

			$wpdb->update(
				$wpdb->prefix . 'ce_clusters',
				array(
					'score'       => min( 100, $score ),
					'score_parts' => wp_json_encode( array(
						'coverage' => (int) round( $coverage ),
						'arch'     => (int) round( $arch ),
						'aeo'      => (int) round( $aeo ),
						'eeat'     => (int) round( $eeat ),
						'hygiene'  => (int) round( $hygiene ),
					) ),
				),
				array( 'id' => $cid )
			);
		}
		return true;
	}

	/**
	 * Heuristic: heading in ALL CAPS or Title Case (Each Word Capitalized).
	 * Short connectives (de, da, e, o...) are ignored; acronyms alone don't trigger.
	 */
	public static function is_over_capitalized( $text ) {
		if ( mb_strlen( $text ) < 8 ) {
			return false;
		}
		// ALL CAPS: uppercase ratio among letters > 75%.
		$letters = preg_replace( '/[^\p{L}]/u', '', $text );
		if ( '' !== $letters ) {
			$upper = preg_replace( '/[^\p{Lu}]/u', '', $letters );
			if ( mb_strlen( $upper ) / max( 1, mb_strlen( $letters ) ) > 0.75 ) {
				return true;
			}
		}
		// Title Case: >= 60% of the significant words AFTER the first start uppercase.
		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $words ) < 3 ) {
			return false;
		}
		$significant = 0;
		$upper_start = 0;
		foreach ( array_slice( $words, 1 ) as $w ) {
			$w = preg_replace( '/^[^\p{L}]+|[^\p{L}]+$/u', '', $w );
			if ( mb_strlen( $w ) < 3 ) {
				continue; // connectives: de, da, e, o, em...
			}
			$significant++;
			$first = mb_substr( $w, 0, 1 );
			if ( $first !== mb_strtolower( $first ) ) {
				$upper_start++;
			}
		}
		return $significant >= 2 && ( $upper_start / max( 1, $significant ) ) >= 0.6;
	}

	/**
	 * Link opportunities: high similarity, no link in either direction.
	 */
	public static function link_opportunities( $limit = 100 ) {
		global $wpdb;
		$settings = get_option( 'ce61_settings', array() );
		$sim_link = isset( $settings['sim_link'] ) ? (float) $settings['sim_link'] : 0.22;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT r.*, ia.title AS title_a, ib.title AS title_b, ia.main_keyword AS kw_a, ib.main_keyword AS kw_b
			 FROM {$wpdb->prefix}ce_relations r
			 JOIN {$wpdb->prefix}ce_index ia ON ia.post_id = r.post_a
			 JOIN {$wpdb->prefix}ce_index ib ON ib.post_id = r.post_b
			 WHERE r.similarity >= %f AND r.link_ab = 0 AND r.link_ba = 0 AND r.status = 'auto'
			 ORDER BY r.similarity DESC LIMIT %d",
			$sim_link, $limit
		), ARRAY_A );
	}

	/**
	 * Senseless links: linked pairs with very low similarity.
	 */
	public static function senseless_links( $limit = 100 ) {
		global $wpdb;
		$settings = get_option( 'ce61_settings', array() );
		$sim_weak = isset( $settings['sim_weak'] ) ? (float) $settings['sim_weak'] : 0.08;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT r.*, ia.title AS title_a, ib.title AS title_b
			 FROM {$wpdb->prefix}ce_relations r
			 JOIN {$wpdb->prefix}ce_index ia ON ia.post_id = r.post_a
			 JOIN {$wpdb->prefix}ce_index ib ON ib.post_id = r.post_b
			 WHERE r.similarity < %f AND (r.link_ab = 1 OR r.link_ba = 1)
			 ORDER BY r.similarity ASC LIMIT %d",
			$sim_weak, $limit
		), ARRAY_A );
	}

	/**
	 * Cannibalization: near-duplicate pairs.
	 */
	public static function cannibalization( $limit = 50 ) {
		global $wpdb;
		$settings = get_option( 'ce61_settings', array() );
		$th = isset( $settings['sim_cannibal'] ) ? (float) $settings['sim_cannibal'] : 0.62;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT r.*, ia.title AS title_a, ib.title AS title_b, ia.main_keyword AS kw_a, ib.main_keyword AS kw_b
			 FROM {$wpdb->prefix}ce_relations r
			 JOIN {$wpdb->prefix}ce_index ia ON ia.post_id = r.post_a
			 JOIN {$wpdb->prefix}ce_index ib ON ib.post_id = r.post_b
			 WHERE r.similarity >= %f
			 ORDER BY r.similarity DESC LIMIT %d",
			$th, $limit
		), ARRAY_A );
	}
}
