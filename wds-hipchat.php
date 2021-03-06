<?php
/*
Plugin Name: WDS HipChat

Plugin URI: http://webdevstudios.com/
Description: HipChat interface for wdschat.webdevstudios.com
Author URI: http://webdevstudios.com
Version: 1.0.0
*/

class _WDS_HipChat {

	protected $admin_slug = 'wds-hipchat-settings';
	protected $opt_name   = 'wds_hipchat_settings';
	protected $admin_name = 'WDS HipChat Settings';
	private   $token      = 'd19d09b3c6afc80746eb7c79f371c2';
	protected $opts       = array();
	protected $schedules  = array();
	protected $rooms      = array();
	protected $from       = false;


	function __construct() {

		define( '_WDS_HipChat_PATH', plugin_dir_path( __FILE__ ) );
		define( '_WDS_HipChat_URL', plugins_url('/', __FILE__ ) );

		// Make sure we have our metabox class
		if ( ! class_exists( 'HipChat' ) )
			require_once( _WDS_HipChat_PATH .'lib/hipchat/src/HipChat/HipChat.php' );

		// Include the Twitter-Text-PHP library
		if ( ! class_exists( 'Twitter_Regex' ) )
			require_once( _WDS_HipChat_PATH .'lib/twitter-text-php/lib/Twitter/Autolink.php' );

		add_action( 'init', array( $this, 'hooks' ), 0 );
		add_action( 'admin_init', array( $this, 'admin_hooks' ) );
		add_action( 'admin_menu', array( $this, 'settings' ) );
		// filter cmb url path
		add_filter( 'cmb_meta_box_url', array( $this, 'update_cmb_url' )  );
		add_filter( 'wds_cpt_core_url', array( $this, 'update_cpt_tax_url' )  );

		add_action( 'wds_hipchat_cron', array( $this, 'cron_callback' ) );
		// @DEV adds a minutely schedule for testing cron
		add_filter( 'cron_schedules', array( $this, 'minutely' ) );
		if ( !$this->opts('frequency') || $this->opts( 'frequency' ) == 'never' )
			return;

		// if a auto-import frequency interval was saved,
		if ( !wp_next_scheduled( 'wds_hipchat_cron' ) ) {
			// schedule a cron to pull updates from instagram
			wp_schedule_event( time(), $this->opts( 'frequency' ), 'wds_hipchat_cron' );
		}

	}

	public function lib_url( $url ) {
		return trailingslashit( _WDS_HipChat_URL.'lib/'. $url );
	}

	public function update_cpt_tax_url( $url ) {
		return $this->lib_url( 'cpt_tax' );
	}

	public function update_cmb_url( $url ) {
		return $this->lib_url( 'cmb' );
	}

	public function hooks() {
		// Custom CPT Setup
		require_once( _WDS_HipChat_PATH .'lib/WDS_Tweet_CPT_Reg.php' );
		$this->cpt = new WDS_Tweet_CPT_Reg();
		// Custom Taxonomies
		if ( !class_exists( 'WDS_TAX_Core' ) )
			require_once( _WDS_HipChat_PATH .'lib/cpt_tax/WDS_TAX_Core.php' );
		$this->account_tax = new WDS_TAX_Core( array( 'Account', 'Accounts', 'wds-hipchat-account' ), $this->cpt->slug );
		$this->room_tax = new WDS_TAX_Core( array( 'Room', 'Rooms', 'wds-hipchat-room' ), $this->cpt->slug );

		// filter the front-end display of these 'tweets'
		add_filter( 'the_content', array( $this, 'twitter_linkify' )  );
	}

	public function admin_hooks() {
		register_setting( $this->admin_slug, $this->opt_name );
		add_settings_section( 'hipchat_setting_section', '', '__return_null', $this->admin_slug );
		add_settings_field( 'hipchat_setting_cron', 'Select Cron Schedule', array( $this, 'hipchat_setting_cron' ), $this->admin_slug, 'hipchat_setting_section' );
		add_settings_field( 'hipchat_setting_terms', 'Import messages with these terms', array( $this, 'hipchat_setting_terms' ), $this->admin_slug, 'hipchat_setting_section' );
		add_settings_field( 'hipchat_setting_name_block', 'Block Messages from Usernames', array( $this, 'hipchat_setting_name_block' ), $this->admin_slug, 'hipchat_setting_section' );
		add_settings_field( 'hipchat_setting_room', 'Select Rooms To Include', array( $this, 'hipchat_setting_room' ), $this->admin_slug, 'hipchat_setting_section' );
		// add_settings_field( 'hipchat_setting_testing', 'HipChat Stuff', array( $this, 'hipchat_setting_testing' ), $this->admin_slug, 'hipchat_setting_section' );
	}

	public function hipchat_setting_cron() {
		?>
		<select name="<?php echo $this->opt_name; ?>[frequency]">
			<option value="never" <?php echo selected( $this->opts( 'frequency' ), 'never' ); ?>><?php _e( 'Manual', 'wds' ); ?></option>
			<?php
			foreach ( $this->schedules() as $key => $value ) {
				echo '<option value="'. $key .'"'. selected( $this->opts( 'frequency' ), $key, false ) .'>'. $value['display'] .'</option>';
			}
			?>
		</select>
		<?php
	}

	public function hipchat_setting_room() {
		foreach ( $this->rooms() as $room_id => $room_name ) {
			echo '<input type="checkbox" name="'. $this->opt_name .'[room_ids][]" class="post-format" id="room-id-'. $room_id .'" value="'. $room_id .'" ',
			checked( in_array( $room_id, $this->opts( 'room_ids' ) ) ),
			'>&nbsp;&nbsp;<label for="room-id-'. $room_id .'" class="">'. $room_name .'</label>
			<br>';
		}
	}

	public function hipchat_setting_terms() {
		?>
		<input type="text" placeholder="<?php _e( 'e.g. #wdschat, totwitter', 'wds' ); ?>" name="<?php echo $this->opt_name; ?>[terms]" value="<?php echo $this->opts( 'terms' ); ?>" />
		<p class="description">Separate terms with commas.</p>
		<?php
	}

	public function hipchat_setting_name_block() {
		?>
		<input type="text" placeholder="<?php _e( 'e.g. webdevstudios, YourMom', 'wds' ); ?>" name="<?php echo $this->opt_name; ?>[block]" value="<?php echo $this->opts( 'block' ); ?>" />
		<p class="description">Separate usernames with commas.</p>
		<?php
	}

	public function hipchat_setting_testing() {
		// echo '<pre>'. htmlentities( print_r( $this->rooms(), true ) ) .'</pre>';

		// // get rooms to check for terms
		// $room_ids = $this->opts( 'room_ids' );
		// if ( empty( $room_ids ) )
		// 	return;

		// foreach ( $room_ids as $room_id ) {
		// 	echo '<pre>$room_id: '. htmlentities( print_r( $room_id, true ) ) .'</pre>';
		// }

		$this->cron_callback();

	}

	/**
	 * Sets up our custom settings page
	 * @since  1.0.0
	 */
	public function settings() {
		// create admin page
		$this->settings_page = add_submenu_page( 'options-general.php', $this->admin_name, $this->admin_name, 'manage_network_options', $this->admin_slug, array( $this, 'settings_page' ) );
	}

	/**
	 * Our admin page
	 * @since  1.0.0
	 */
	public function settings_page() {
		require_once( 'hipchat-settings.php' );
	}

	public function opts( $index = '' ) {
		$this->opts = !empty( $this->opts ) ? $this->opts : get_option( $this->opt_name );

		if ( $index ) {
			return isset( $this->opts[$index] ) ? $this->opts[$index] : false;
		}

		return $this->opts;
	}

	public function schedules() {
		$this->schedules = !empty( $this->schedules ) ? $this->schedules : wp_get_schedules();
		return $this->schedules;
	}

	public function rooms( $room_id = 0 ) {
		// require_once( 'sampledata.php' );
		$msg = 'already set';
		if ( empty( $this->rooms ) ) {
			$msg = 'use transient';
			// check transient for rooms
			$this->rooms = get_transient( $this->opt_name.'_rooms' );
			// if none
			if ( empty( $this->rooms ) ) {
				$msg = 'regenerate transient';
				// connect to the api
				$hc = new HipChat\HipChat($this->token);
				// and grab rooms
				$this->rooms = $hc->get_rooms();

				$rooms = array();
				if ( empty( $this->rooms ) )
					return $rooms;

				foreach ( $this->rooms as $key => $room ) {
					$rooms[$room->room_id] = $room->name;
				}
				$this->rooms = $rooms;

				// then save to a transient (expires once a day)
				set_transient( $this->opt_name.'_rooms', $this->rooms, 86400 );
			}
		}
		// trigger_error( $msg );
		if ( $room_id )
			return isset( $this->rooms[$room_id] ) ? $this->rooms[$room_id] : false;

		return $this->rooms;
	}

	/**
	 * Add import function to cron
	 * @since  1.2.0
	 */
	public function cron_callback() {

		// get terms to check for
		$terms = $this->opts( 'terms' );
		if ( empty( $terms ) )
			return;

		// get rooms to check for terms
		$room_ids = $this->opts( 'room_ids' );
		if ( empty( $room_ids ) )
			return;

		// split into array
		$terms = ( false !== strpos( $terms, ',' ) ) ? explode( ',', $terms ) : array( $terms );


		$blocked = $this->opts( 'blocked' );
		// split into array
		$blocked = ( false !== strpos( $blocked, ',' ) ) ? explode( ',', $blocked ) : array( $blocked );

		foreach ( $room_ids as $room_id ) {
			$hc = new HipChat\HipChat($this->token);
			$this->room_history = $hc->get_rooms_history( $room_id );
			// require_once( 'sampledata.php' );

			if ( !$this->room_history )
				return;

			// loop messages
			foreach ( $this->room_history as $message ) {
				$from_name = $message->from->name;
				$from_name = sanitize_text_field( isset( $from_name ) ? $from_name : 'no-name' );
				// skip messages posted by this site
				if ( $from_name == $this->from() || apply_filters( 'wds_hipchat_skip_message', false, $this->from(), $message ) )
					continue;

				$keep = true;
				if ( !empty( $blocked ) ) {
					foreach ( $blocked as $block ) {
						// does author match a blocked name?
						if ( strtolower( trim( $from_name ) ) == strtolower( trim( $block ) ) ) {
							// if found, we don't keep this message
							$keep = false;
							break;
						}
					}
					// so loop to the next message
					if ( !$keep ) continue;
				}

				$keep = false;
				foreach ( $terms as $term ) {
					// search for our terms in the message
					$pos = strpos( $message->message, trim( $term ) );
					if ( $pos !== false ) {
						// if found, we keep this message
						$keep = true;
						break;
					}
				}
				// otherwise, loop to the next message
				if ( !$keep ) continue;

				// parse a WP readable date from message date
				$post_date = date( 'Y-m-d H:i:s', strtotime( $message->date ) );
				// generate a post title from name & date
				$post_title = sanitize_text_field( 'OH:'. strtotime( $message->date ) );
				// no filter because it doesn't handle line-breaks well. :(
				$content = $message->message;
				// room name
				$room_name = $this->rooms( $room_id );
				$room_name = sanitize_text_field( isset( $room_name ) ? $room_name : 'No Room' );
				// If this name is the same as an existing post, bail here
				if ( get_page_by_title( $post_title, OBJECT, $this->cpt->slug ) )
					continue;

				// create our post data
				$post = array(
					// linkify the hashtags
				  'post_content' => str_replace( '#wdschat', '<a href="http://twitter.com/search?q=%#wdschat">#wdschat</a>', $content ),
				  'post_date' => $post_date,
				  'post_date_gmt' => $post_date,
				  'post_status' => 'publish',
				  'post_title' => $post_title,
				  'post_type' => $this->cpt->slug,
				);
				do_action( 'wds_hipchat_pre_save_post', $post, $message );

				// and insert our post
				$new_post_id = wp_insert_post( $post, true );
				if ( is_wp_error( $new_post_id ) )
					continue;
				// if there were no errors, save our post-meta
				wp_set_object_terms( $new_post_id, array( $from_name ), $this->account_tax->slug );
				wp_set_object_terms( $new_post_id, array( $room_name ), $this->room_tax->slug );
				update_post_meta( $new_post_id, 'wds-author-id', sanitize_text_field( isset( $message->from->user_id ) ? $message->from->user_id : 0 ) );

				do_action( 'wds_hipchat_saved_post', $new_post_id, $message );
				// send a message with permalink to hipchat
				$message = apply_filters( 'wds_hipchat_message', 'New #wdschat! - <a href="'. get_permalink( $new_post_id ) .'">'. $post_title .'</a><br>'."\n".'<blockquote>'. substr( $content, 0, 120 ) .'</blockquote><br>'."\n", $new_post_id, $message );
				$hc->message_room( $room_id, $this->from(), $message );
			}
		}

	}

	public function from() {
		if ( !$this->from )
			$this->from = sanitize_html_class( substr( apply_filters( 'wds_hipchat_from_name', get_bloginfo( 'name' ) ), 0, 15 ), 'WebDevStudios' );
		return $this->from;
	}

	/**
	 * @DEV Adds once minutely to the existing schedules for easier cron testing.
	 * @since  1.2.0
	 */
	public function minutely( $schedules ) {
		$schedules['minutely'] = array(
			'interval' => 60,
			'display'  => 'Once Every Minute'
		);
		return $schedules;
	}

	/**
	 * Parses tweets and generates HTML anchor tags around URLs, usernames,
	 * username/list pairs and hashtags.
	 *
	 * @link https://github.com/mzsanford/twitter-text-php
	 *
	 * @param  string $content Post content
	 * @return string          Modified post content
	 */
	public function twitter_linkify( $content ) {

		if ( get_post_type() == $this->cpt->slug )
			return Twitter_Autolink::create( $content )->setNoFollow( false )->addLinks();

		return $content;
	}

}
$GLOBALS['_WDS_HipChat'] = new _WDS_HipChat;