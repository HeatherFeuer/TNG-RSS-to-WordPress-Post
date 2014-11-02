<?php
/*
Plugin Name: TNG RSS to WordPress Post
Description: Uses the TNG-WP integration plugin to import the TNG RSS feed and create a new post for each new entry per date.
Version: 1.6
Author: Nate Jacobs
Author URI: http://natejacobs.com
License: GPL2
Text Domain: tng-wp-rss
Domain Path: /languages
GitHub Plugin URI: NateJacobs/TNG-RSS-to-WordPress-Post
*/

// this plugin is based off the idea of Marcus Zuhorst and created by Andreas Krischer (email: kontakt@akbyte.com).
// http://www.tngforum.us/index.php?showtopic=9227

/*  Copyright 2014  Nate Jacobs

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/** 
 *	A class to manage the creation of posts off the TNG RSS feed.
 *
 *	@author		Nate Jacobs
 *	@date		9/11/14
 *	@since		1.0
 */
class TNG_RSS {
	
	protected $tng_rss_category;

	/** 
	 *	Hook into WordPress and start things off.
	 *
	 *	@author		Nate Jacobs
	 *	@date		9/11/14
	 *	@since		1.0
	 */
	public function __construct() {
		add_action('plugins_loaded', array($this, 'localization'));
		register_activation_hook(__FILE__, array($this, 'activation'));
		register_deactivation_hook(__FILE__, array($this, 'deactivation'));
		// uncomment the line below for testing purposes, it will trigger the update script every time a page is viewed on the dashboard.
		//add_action('admin_init', [$this, 'tng_wp_rss_update']);
		
		$this->tng_rss_category = __('Genealogy Updates', 'tng-wp-rss');
	}
	
	/** 
	 *	Load up the language files.
	 *
	 *	@author		Nate Jacobs
	 *	@date		9/12/14
	 *	@since		1.0
	 */
	public function localization() {
		load_plugin_textdomain('tng-wp-rss', false, dirname(plugin_basename(__FILE__)).'/languages/');
	}
	
	/** 
	 *	Create the new Genealogy Update category.
	 *	Schedule an update of the TNG RSS feed.
	 *
	 *	@author		Nate Jacobs
	 *	@date		9/11/14
	 *	@since		1.0
	 */
	public function activation() {
		wp_create_category($this->tng_rss_category);	
		
		/** 
		 *	Filter the scheduled recurrence event time.
		 *
		 *	@author		Nate Jacobs
		 *	@date		11/1/14
		 *	@since		1.6
		 *
		 *	@param		string	The cron interval.
		 */
		$schedule = apply_filters('tng_wp_rss_post_schedule', 'daily');
		
		if(! wp_next_scheduled('tng_wp_rss_update')) {
			wp_schedule_event(time(), $schedule, 'tng_wp_rss_update');
		}
	}
	
	/** 
	 *	Unschedule the cron event on deactivation.
	 *
	 *	@author		Nate Jacobs
	 *	@date		9/14/14
	 *	@since		1.1
	 */
	public function deactivation() {
		// make sure the user can activate/deactivate plugins
		if(!current_user_can('activate_plugins')) {
			return;
		}
		
		// get the name of the plugin
        $plugin = isset($_REQUEST['plugin']) ? $_REQUEST['plugin'] : '';
        
        // make sure it is a valid request
        check_admin_referer("deactivate-plugin_{$plugin}");
        
        // unschedule the cron event
        wp_unschedule_event(wp_next_scheduled('tng_wp_rss_update'), 'tng_wp_rss_update');
	}
	
	/** 
	 *	Retrieve the newest updates from TNG RSS feed.
	 *	Create a new post for each day.
	 *
	 *	@author		Nate Jacobs
	 *	@date		9/11/14
	 *	@since		1.0
	 */
	public function tng_wp_rss_update() {
		$url = $this->get_tng_rss_url();
		
		// only process the feed if there is a valid URL
		if($url) {
			$feed = fetch_feed($url.'tngrss.php');
			
			if(!is_wp_error($feed)) {
				$maxitems = $feed->get_item_quantity();
				$rss_items = $feed->get_items();
				
				$last_item_read_date = get_option('tng_wp_rss_last_read', 0);
				
				// if the feed has items
				if($maxitems !== 0) {
					$new_item_by_date = array();
					
					foreach($rss_items as $item) {
						$pub_date_epoch = $item->get_date('U');
						
						// get the array of items organized by date
						// only use the items posted after the last update
						if($pub_date_epoch > $last_item_read_date) {
							$new_item_by_date[strtotime($item->get_date('Y-m-d'))][] = $item;
						}
					}
					
					$new_date = $this->tng_wp_rss_add_post($new_item_by_date);
					
					if($new_date) {
						update_option('tng_wp_rss_last_read', $new_date);
					}
				}
			}
		}
	}
	
	/** 
	 *	Create a new post for each new day.
	 *
	 *	@author		Nate Jacobs
	 *	@date		9/12/14
	 *	@since		1.0
	 *
	 *	@param		array	$items	An array of SimplePie objects, each item of the RSS feed.
	 */
	protected function tng_wp_rss_add_post($items) {
		
		$new_last_updated = false;
		
		if(is_array($items) && !empty($items)) {
			// get the most recent item date
			$new_last_updated_key = key($items);
			$new_last_updated = $items[$new_last_updated_key][0]->get_date('U');
			
			foreach($items as $date => $item) {
				$class = 'list-unstyled';
				
				/** 
				 *	Filter the name of the class used in the unordered list.
				 *	The default is list-unstyled.
				 *
				 *	@author		Nate Jacobs
				 *	@date		9/12/14
				 *	@since		1.0
				 *
				 *	@param		string	$content		The class name for unordered list <ul>.
				 */
				$class = apply_filters('tng_wp_rss_list_class', $class);
				
				/** 
				 *	Filter the content that appears before the unordered list of new TNG updates.
				 *
				 *	@author		Nate Jacobs
				 *	@date		9/12/14
				 *	@since		1.0
				 *
				 *	@param		string	$content		The content before the unordered list.
				 */
				$content = apply_filters('tng_wp_rss_before_content', '');
				
				$content .= '<ul class="'.$class.'">';
				
				foreach($item as $post) {
					$title = $post->get_title();
					$description = $post->get_description();
					if(!empty($description)) {
						$description = '('.html_entity_decode($description).')';
					} else {
						$description = '';
					}
					
					$content .= '<li>';
					$content .= '<a href="'.$post->get_permalink().'">'.$title.'</a> '.$description;
					$content .= '</li>';
				}
				$content .= '</ul>';
				
				/** 
				 *	Filter the content that appears after the unordered list of new TNG updates.
				 *
				 *	@author		Nate Jacobs
				 *	@date		9/12/14
				 *	@since		1.0
				 *
				 *	@param		string	$content		The content after the unordered list.
				 */
				$content .= apply_filters('tng_wp_rss_after_content', '');
				
				/** 
				 *	Filter the HTML string that will become the body of the post.
				 *
				 *	@author		Nate Jacobs
				 *	@date		9/12/14
				 *	@since		1.0
				 *
				 *	@param		string	$content		The post content from the TNG RSS feed.
				 */
				$content = apply_filters('tng_wp_rss_post_content', $content);
				
				// translators: post title date format
				$date_format = __('F d Y', 'tng-wp-rss');
				
				$post_title = sprintf(__('TNG Updates for %s', 'tng-wp-rss'), date_i18n($date_format, $date));
				
				/** 
				 *	Filter the post title before the post is created.
				 *
				 *	@author		Nate Jacobs
				 *	@date		9/14/14
				 *	@since		1.0
				 *
				 *	@param		string	$post_title	The post title.
				 *	@param		string	$content		The html content of the post.
				 *	@param		string	$date		The date of the update in timestamp format.
				 */
				$post_title = apply_filters('tng_wp_rss_post_title', $post_title, $content, $date);
				
				$category = get_cat_ID($this->tng_rss_category);
				
				$author_id = get_users(array('role' => 'administrator'));
				
				/** 
				 *	Filter the post author. A user ID is expected in return.
				 *
				 *	@author		Nate Jacobs
				 *	@date		9/14/14
				 *	@since		1.0
				 *
				 *	@param		int	$author_id[0]->ID	The user ID of the post author.
				 */
				$post_author = apply_filters('tng_wp_rss_post_author_id', $author_id[0]->ID);
				
				$args = array(
					'post_content' => $content,
	                'post_title' => wp_strip_all_tags($post_title),
	                'post_status' => 'publish',
	                'post_category' => array($category),
	                'post_author' => $post_author,
	                'post_date' => date('Y-m-d H:i:s', $date)

				);
				
				$post_id = wp_insert_post($args, true);
				
				/** 
				 *	The newly created post ID is passed.
				 *
				 *	@author		Nate Jacobs
				 *	@date		9/12/14
				 *	@since		1.0
				 *
				 *	@param		int|WP_Error		$post_id		The post ID of the newly created post or an WP_Error object if there is a problem.
				 */
				do_action('tng_wp_rss_new_post_id', $post_id);
			}
		}
		
		return $new_last_updated;
	}
	
	/** 
	 *	Return the TNG url based off the admin url from the integration plugin.
	 *
	 *	@author		Nate Jacobs
	 *	@date		9/11/14
	 *	@since		1.0
	 */
	protected function get_tng_rss_url() {
		/** 
		 *	Filter the TNG RSS URL.
		 *
		 *	@author		Nate Jacobs
		 *	@date		11/1/14
		 *	@since		1.6
		 *
		 *	@param		string|bool	The url from the WP-TNG integration plugin.
		 */
		$url = apply_filters('tng_wp_rss_url',  get_option('mbtng_url_to_admin'));
		
		// there is no admin url set with the TNG/WP integration plugin
		if(!$url) {
			return false;
		}
		
		return trailingslashit(substr($url, 0, strrpos($url, '/admin.php')));
	}
}

$tng_rss_post_import = new TNG_RSS();

add_action('tng_wp_rss_update', 'tng_wp_rss_update_run');
function tng_wp_rss_update_run() {
	$rss = new TNG_RSS();
	$rss->tng_wp_rss_update();
}