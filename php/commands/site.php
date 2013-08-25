<?php

/**
 * Perform site-wide operations.
 *
 * @package wp-cli
 */
class Site_Command extends WP_CLI_Command {

	/**
	 * Delete comments.
	 */
	private function _empty_comments() {
		global $wpdb;

		// Empty comments and comment cache
		$comment_ids = $wpdb->get_col( "SELECT comment_ID FROM $wpdb->comments" );
		foreach ( $comment_ids as $comment_id ) {
			wp_cache_delete( $comment_id, 'comment' );
			wp_cache_delete( $comment_id, 'comment_meta' );
		}
		$wpdb->query( "TRUNCATE $wpdb->comments" );
		$wpdb->query( "TRUNCATE $wpdb->commentmeta" );
	}

	/**
	 * Delete all posts.
	 */
	private function _empty_posts() {
		global $wpdb;

		// Empty posts and post cache
		$posts_query = "SELECT ID FROM $wpdb->posts";
		$posts = new WP_CLI\Iterators\Query( $posts_query, 10000 );

		$taxonomies = get_taxonomies();

		while ( $posts->valid() ) {
			$post_id = $posts->current()->ID;

			wp_cache_delete( $post_id, 'posts' );
			wp_cache_delete( $post_id, 'post_meta' );
			foreach ( $taxonomies as $taxonomy )
				wp_cache_delete( $post_id, "{$taxonomy}_relationships" );
			wp_cache_delete( $wpdb->blogid . '-' . $post_id, 'global-posts' );

			$posts->next();
		}
		$wpdb->query( "TRUNCATE $wpdb->posts" );
		$wpdb->query( "TRUNCATE $wpdb->postmeta" );
	}

	/**
	 * Delete terms, taxonomies, and tax relationships.
	 */
	private function _empty_taxonomies() {
		global $wpdb;

		// Empty taxonomies and terms
		$terms = $wpdb->get_results( "SELECT term_id, taxonomy FROM $wpdb->term_taxonomy" );
		$ids = array();
		foreach ( (array) $terms as $term ) {
			$taxonomies[] = $term->taxonomy;
			$ids[] = $term->term_id;
			wp_cache_delete( $term->term_id, $term->taxonomy );
		}

		$taxonomies = array_unique( $taxonomies );
		foreach ( $taxonomies as $taxonomy ) {
			if ( isset( $cleaned[$taxonomy] ) )
				continue;
			$cleaned[$taxonomy] = true;

			wp_cache_delete( 'all_ids', $taxonomy );
			wp_cache_delete( 'get', $taxonomy );
			delete_option( "{$taxonomy}_children" );
		}
		$wpdb->query( "TRUNCATE $wpdb->terms" );
		$wpdb->query( "TRUNCATE $wpdb->term_taxonomy" );
		$wpdb->query( "TRUNCATE $wpdb->term_relationships" );
	}

	/**
	 * Insert default terms.
	 */
	private function _insert_default_terms() {
		global $wpdb;

		// Default category
		$cat_name = __( 'Uncategorized' );

		/* translators: Default category slug */
		$cat_slug = sanitize_title( _x( 'Uncategorized', 'Default category slug' ) );

		if ( global_terms_enabled() ) {
			$cat_id = $wpdb->get_var( $wpdb->prepare( "SELECT cat_ID FROM {$wpdb->sitecategories} WHERE category_nicename = %s", $cat_slug ) );
			if ( $cat_id == null ) {
				$wpdb->insert( $wpdb->sitecategories, array('cat_ID' => 0, 'cat_name' => $cat_name, 'category_nicename' => $cat_slug, 'last_updated' => current_time('mysql', true)) );
				$cat_id = $wpdb->insert_id;
			}
			update_option('default_category', $cat_id);
		} else {
			$cat_id = 1;
		}

		$wpdb->insert( $wpdb->terms, array('term_id' => $cat_id, 'name' => $cat_name, 'slug' => $cat_slug, 'term_group' => 0) );
		$wpdb->insert( $wpdb->term_taxonomy, array('term_id' => $cat_id, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1));
	}

	/**
	 * Empty a site of its content (posts, comments, and terms).
	 *
	 * ## OPTIONS
	 *
	 * --yes
	 * : Proceed to empty the site without a confirmation prompt.
	 *
	 * @subcommand empty
	 * @synopsis [--yes]
	 */
	public function _empty( $args, $assoc_args ) {

		WP_CLI::confirm( 'Are you sure you want to empty the site at ' . site_url() . ' of all posts, comments, and terms?', $assoc_args );

		$this->_empty_posts();
		$this->_empty_comments();
		$this->_empty_taxonomies();
		$this->_insert_default_terms();

		WP_CLI::success( 'The site at ' . site_url() . ' was emptied.' );
	}

	public function move( $args, $assoc_args ) {

		if ( empty( $assoc_args['blog_id'] ) )
			return;

		if ( empty( $assoc_args['term_id'] ) )
			$term_id = 0;

		$blog_id = (int) $assoc_args['blog_id'];
		$term_id = (int) $assoc_args['term_id'];

		WP_CLI::line( "Moving objects to " . $blog_id );

		global $wpdb, $switched;

		if ( $term_id > 0 ) {
			$count_query_str =$wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts as posts, $wpdb->term_taxonomy as term_taxonomy, $wpdb->term_relationships as term_relationships " . 
						 "WHERE posts.post_type = 'post' AND term_taxonomy.term_id = '%d' AND " . 
						 "term_taxonomy.term_taxonomy_id = term_relationships.term_taxonomy_id AND " . 
						 "term_relationships.object_id = posts.ID ", $term_id );
			$query_str =$wpdb->prepare( "SELECT * FROM $wpdb->posts as posts, $wpdb->term_taxonomy as term_taxonomy, $wpdb->term_relationships as term_relationships " . 
						 "WHERE posts.post_type = 'post' AND term_taxonomy.term_id = '%d' AND " . 
						 "term_taxonomy.term_taxonomy_id = term_relationships.term_taxonomy_id AND " . 
						 "term_relationships.object_id = posts.ID ", $term_id );
		} else {
			$count_query_str = "SELECT COUNT(*) FROM $wpdb->posts as posts WHERE post_type = 'post'";
			$query_str = "SELECT * FROM $wpdb->posts as posts WHERE post_type = 'post'";
		}

		$total_posts = $wpdb->get_var( $count_query_str );

		WP_CLI::line( "Total Posts: " . $total_posts );

		// Break up posts into chunks
		$chunk_size = 500;
		$total_chunks = $total_posts / $chunk_size;

		for ( $i = 0; $i < $total_chunks; $i++ ) {
			$posts = $wpdb->get_results( $wpdb->prepare( $query_str . " LIMIT %d,%d", ( $i * $chunk_size ), $chunk_size ) );

			foreach ( $posts as $post ) {

				// Grab post meta for post
				$post_metas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->postmeta WHERE post_id = '%d'", $post->ID ) );

				// Grab attachments
    			$attachments = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->posts WHERE post_parent = '%d' AND post_type = 'attachment'", $post->ID ) );

    			switch_to_blog( $blog_id );

    			// Map arguments to array
    			$post_args = array(
    				'menu_order' => $post->menu_order,
					'comment_status' => $post->comment_status,
					'ping_status' => $post->ping_status,
					'pinged' => $post->pinged,
					'post_author' => $post->post_author, 
					'post_content' => $post->post_content,
					'post_date' => $post->post_date,
					'post_date_gmt' => $post->post_date_gmt,
					'post_excerpt' => $post->post_excerpt,
					'post_name' => $post->post_name, // collisions?
					'post_parent' => $post->post_parent,
					'post_password' => $post->post_password,
					'post_status' => $post->post_status,
					'post_title' => $post->post_title,
					'post_type' => $post->post_type,
					'to_ping' => $post->to_ping,
				);

    			// Create post in new blog
    			$new_post_id = wp_insert_post( $post_args );

    			if ( empty( $new_post_id ) ) {
    				WP_CLI::error( 'A post failed to be moved' );
    				continue;
    			}

    			// Transfer post meta
    			foreach ( $post_metas as $post_meta ) {
    				update_post_meta( $new_post_id, $post_meta->meta_key, $post_meta->meta_value );
    			}

    			// Transfer attachments
    			foreach ( $attachments as $attachment ) {
    				// Move image to blog
    				$new_image_url = media_sideload_image( $attachment->guid, $new_post_id );

    				// Update URL's in current post
    				$new_post_content = str_replace( $attachment->guid, $new_image_url, $post->post_content );
    				wp_update_post(
    					array(
    						'ID' => $new_post_id,
    						'post_content' => $new_post_content,
    					)
    				);
    			}


    			restore_current_blog();

				// Grab comments for post
				$post_comments = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->comments WHERE comment_post_ID = '%d'", $post->ID ) );

				foreach ( $post_comments as $post_comment ) {

					// Grab comment meta for post
					$comment_metas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->commentmeta WHERE comment_id = '%d'", $post_comments->comment_ID ) );

					switch_to_blog( $blog_id );

					// Map comment arguments
					$comment_args = array(
					    'comment_author' => $post_comment->comment_author,
					    'comment_author_email' => $post_comment->comment_author_email,
					    'comment_author_url' => $post_comment->comment_author_url,
					    'comment_content' => $post_comment->comment_content,
					    'comment_type' => $post_comment->comment_type,
					    'comment_parent' => $post_comment->comment_parent,
					    'user_id' => $post_comment->user_id,
					    'comment_author_IP' => $post_comment->comment_author_IP,
					    'comment_agent' => $post_comment->comment_agent,
					    'comment_date' => $post_comment->comment_date,
					    'comment_approved' => $post_comment->comment_approved,
					);

					// Insert new comment
					$new_comment_id = wp_insert_comment( $comment_args );

					if ( empty( $new_comment_id ) ) {
	    				WP_CLI::error( 'A comment failed to be moved' );
	    				continue;
	    			}

	    			// Transfer comment meta
	    			foreach ( $comment_metas as $comment_meta ) {
	    				update_post_meta( $new_comment_id, $comment_meta->meta_key, $comment_meta->meta_value );
	    			}

					restore_current_blog();

				}
			}
		}

		WP_CLI::success( 'Posts moved!' );

		// Need to account for taxonomies
	}

	/**
	 * Delete a site in a multisite install.
	 *
	 * ## OPTIONS
	 *
	 * <blog-id>
	 * : The id of the blog to delete. If not provided, you must set the --slug parameter.
	 *
	 * --slug=<slug>
	 * : Path of the blog to be deleted. Subdomain on subdomain installs, directory on subdirectory installs.
	 *
	 * --yes
	 * : Answer yes to the confirmation message.
	 *
	 * --keep-tables
	 * : Delete the blog from the list, but don't drop it's tables.
	 *
	 * @synopsis [<site-id>] [--slug=<slug>] [--yes] [--keep-tables]
	 */
	function delete( $args, $assoc_args ) {
		if ( !is_multisite() ) {
			WP_CLI::error( 'This is not a multisite install.' );
		}

		if ( isset( $assoc_args['slug'] ) ) {
			$blog = get_blog_details( trim( $assoc_args['slug'], '/' ) );
		} else {
			if ( empty( $args ) ) {
				WP_CLI::error( "Need to specify a blog id." );
			}

			$blog_id = $args[0];

			$blog = get_blog_details( $blog_id );
		}

		if ( !$blog ) {
			WP_CLI::error( "Site not found." );
		}

		WP_CLI::confirm( "Are you sure you want to delete the $blog->siteurl site?", $assoc_args );

		wpmu_delete_blog( $blog->blog_id, !isset( $assoc_args['keep-tables'] ) );

		WP_CLI::success( "The site at $blog->siteurl was deleted." );
	}

	/**
	 * Get site (network) data for a given id.
	 *
	 * @param int     $site_id
	 * @return bool|array False if no network found with given id, array otherwise
	 */
	private function _get_site( $site_id ) {
		global $wpdb;
		// Load site data
		$sites = $wpdb->get_results( "SELECT * FROM $wpdb->site WHERE `id` = ".$wpdb->escape( $site_id ) );
		if ( count( $sites ) > 0 ) {
			// Only care about domain and path which are set here
			return $sites[0];
		}

		return false;
	}

	/**
	 * Create a site in a multisite install.
	 *
	 * ## OPTIONS
	 *
	 * --slug=<slug>
	 * : Path for the new site. Subdomain on subdomain installs, directory on subdirectory installs.
	 *
	 * --title=<title&gt;
	 * : Title of the new site. Default: prettified slug.
	 *
	 * --email=<email>
	 * : Email for Admin user. User will be created if none exists. Assignement to Super Admin if not included.
	 *
	 * --network_id=<network-id>
	 * : Network to associate new site with. Defaults to current network (typically 1).
	 *
	 * --private
	 * : If set, the new site will be non-public (not indexed)
	 *
	 * --porcelain
	 * : If set, only the site id will be output on success.
	 *
	 * @synopsis --slug=<slug> [--title=<title>] [--email=<email>] [--network_id=<network-id>] [--private] [--porcelain]
	 */
	public function create( $_, $assoc_args ) {
		if ( !is_multisite() ) {
			WP_CLI::error( 'This is not a multisite install.' );
		}

		global $wpdb;

		$base = $assoc_args['slug'];
		$title = isset( $assoc_args['title'] ) ? $assoc_args['title'] : ucfirst( $base );

		$email = empty( $assoc_args['email'] ) ? '' : $assoc_args['email'];

		// Site
		if ( !empty( $assoc_args['network_id'] ) ) {
			$site = $this->_get_site( $assoc_args['network_id'] );
			if ( $site === false ) {
				WP_CLI::error( sprintf( 'Network with id %d does not exist.', $assoc_args['network_id'] ) );
			}
		}
		else {
			$site = wpmu_current_site();
		}

		$public = !isset( $assoc_args['private'] );

		// Sanitize
		if ( preg_match( '|^([a-zA-Z0-9-])+$|', $base ) ) {
			$base = strtolower( $base );
		}

		// If not a subdomain install, make sure the domain isn't a reserved word
		if ( !is_subdomain_install() ) {
			$subdirectory_reserved_names = apply_filters( 'subdirectory_reserved_names', array( 'page', 'comments', 'blog', 'files', 'feed' ) );
			if ( in_array( $base, $subdirectory_reserved_names ) ) {
				WP_CLI::error( 'The following words are reserved and cannot be used as blog names: ' . implode( ', ', $subdirectory_reserved_names ) );
			}
		}

		// Check for valid email, if not, use the first Super Admin found
		// Probably a more efficient way to do this so we dont query for the
		// User twice if super admin
		$email = sanitize_email( $email );
		if ( empty( $email ) || !is_email( $email ) ) {
			$super_admins = get_super_admins();
			$email = '';
			if ( !empty( $super_admins ) && is_array( $super_admins ) ) {
				// Just get the first one
				$super_login = $super_admins[0];
				$super_user = get_user_by( 'login', $super_login );
				if ( $super_user ) {
					$email = $super_user->user_email;
				}
			}
		}

		if ( is_subdomain_install() ) {
			$path = '/';
			$url = $newdomain = $base.'.'.preg_replace( '|^www\.|', '', $site->domain );
		}
		else {
			$newdomain = $site->domain;
			$path = '/' . trim( $base, '/' ) . '/';
			$url = $site->domain . $path;
		}

		$user_id = email_exists( $email );
		if ( !$user_id ) { // Create a new user with a random password
			$password = wp_generate_password( 12, false );
			$user_id = wpmu_create_user( $base, $password, $email );
			if ( false == $user_id ) {
				WP_CLI::error( "Can't create user." );
			}
			else {
				wp_new_user_notification( $user_id, $password );
			}
		}

		$wpdb->hide_errors();
		$id = wpmu_create_blog( $newdomain, $path, $title, $user_id, array( 'public' => $public ), $site->id );
		$wpdb->show_errors();
		if ( !is_wp_error( $id ) ) {
			if ( !is_super_admin( $user_id ) && !get_user_option( 'primary_blog', $user_id ) ) {
				update_user_option( $user_id, 'primary_blog', $id, true );
			}
			// Prevent mailing admins of new sites
			// @TODO argument to pass in?
			// $content_mail = sprintf(__( "New site created by WP Command Line Interface\n\nAddress: %2s\nName: %3s"), get_site_url($id), stripslashes($title));
			// wp_mail(get_site_option('admin_email'), sprintf(__('[%s] New Site Created'), $current_site->site_name), $content_mail, 'From: "Site Admin" <'.get_site_option( 'admin_email').'>');
		}
		else {
			WP_CLI::error( $id->get_error_message() );
		}

		if ( isset( $assoc_args['porcelain'] ) )
			WP_CLI::line( $id );
		else
			WP_CLI::success( "Site $id created: $url" );
	}

	/**
	 * List all sites in a multisite install.
	 *
	 * ## OPTIONS
	 *
	 * --network=<id>
	 * : The network to which the sites belong.
	 *
	 * --fields=<fields>
	 * : Comma-separated list of fields to show.
	 *
	 * --format=<format>
	 * : Output list as table, csv, json or url. Defaults to table.
	 *
	 * ## EXAMPLES
	 *
	 *     # Output a simple list of site URLs
	 *     wp site list --fields=url --format=csv | tail -n +2
	 *
	 * @subcommand list
	 * @synopsis [--network=<id>] [--format=<format>] [--fields=<fields>]
	 */
	function _list( $_, $assoc_args ) {
		if ( !is_multisite() ) {
			WP_CLI::error( 'This is not a multisite install.' );
		}

		global $wpdb;

		if ( isset( $assoc_args['fields'] ) ) {
			$assoc_args['fields'] = preg_split( '/,[ \t]*/', $assoc_args['fields'] );
		}

		$defaults = array(
			'format' => 'table',
			'fields' => array( 'blog_id', 'url', 'last_updated', 'registered' ),
		);
		$assoc_args = array_merge( $defaults, $assoc_args );

		$where = array();
		if ( isset( $assoc_args['network'] ) ) {
			$where['site_id'] = $assoc_args['network'];
		}

		$iterator_args = array(
			'table' => $wpdb->blogs,
			'where' => $where,
		);
		$it = new \WP_CLI\Iterators\Table( $iterator_args );

		$it = \WP_CLI\Utils\iterator_map( $it, function( $blog ) {
			$blog->url = $blog->domain . $blog->path;
			return $blog;
		} );

		WP_CLI\Utils\format_items( $assoc_args['format'], $it, $assoc_args['fields'] );
	}
}

WP_CLI::add_command( 'site', 'Site_Command' );

