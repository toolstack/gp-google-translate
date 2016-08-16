<?php
/*
Plugin Name: GP Machine Translate
Plugin URI: http://glot-o-matic.com/gp-machine-translate
Description: Machine Translate plugin for GlotPress.
Version: 0.8
Author: Greg Ross
Author URI: http://toolstack.com
Tags: glotpress, glotpress plugin, translate, google, bing, yandex, microsoft
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

class GP_Machine_Translate {
	public $id = 'gp-machine-translate';

	private $version = '0.8';
	private $key;
	private $provider_code = false;
	private $provider;
	private	$providers;
	private $banners;
	private $locales;
	private $client_id;
	
	public function __construct() {
		// Handle the WordPress user profile items
		add_action( 'show_user_profile', array( $this, 'show_user_profile' ), 10, 1 );
		add_action( 'edit_user_profile', array( $this, 'edit_user_profile' ), 10, 1 );
		add_action( 'personal_options_update', array( $this, 'personal_options_update' ), 10, 1 );
		add_action( 'edit_user_profile_update', array( $this, 'edit_user_profile_update' ), 10, 1 );
		
		// Add the admin page to the WordPress settings menu.
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 10, 1 );

		$this->providers = array( 'Google Translate', 'Microsoft Translator', 'transltr.org', 'Yandex.Translate' );
		$this->banners = array( 'Google Translate' => 'Google Translate', 'Microsoft Translator' => 'Microsoft Translator', 'transltr.org' => 'transltr.org', 'Yandex.Translate' => '<a href="http://translate.yandex.com/" target="_blank">Powered by Yandex.Translate</a>' );
		$provider_includes = array( 'Yandex.Translate' => 'yandex.locales.php', 'Microsoft Translator' => 'microsoft.locales.php', 'Google Translate' => 'google.locales.php', 'transltr.org' => 'transltr.locales.php' );

		if( get_option( 'gp_machine_translate_version', '0.7' ) != $this->version ) {
			$this->upgrade();
		}
		
		// Get the global translate provider from the WordPress options table.
		$this->provider = get_option( 'gp_machine_translate_provider', 'transltr.org' );

		// Get the global translate key from the WordPress options table.
		$this->key = get_option('gp_machine_translate_key');

		// Get the global translate key from the WordPress options table.
		$this->client_id = get_option('gp_machine_translate_client_id');
		
		include_once( $provider_includes[$this->provider] );
		
		$this->locales = $gp_machine_translate_locales;
	
		// Check to see if there is a user currently logged in.
		if ( is_user_logged_in() ) {
			// If someone is logged in, get their user object.
			$user_obj = wp_get_current_user();
			
			// Load the user translate key from the WordPress user meta table, using the currently logged in user id.
			$user_key = get_user_meta( $user_obj->ID, 'gp_machine_translate_key', true );
			
			// If there is a user key, override the global key.
			if( $user_key ) { $this->key = $user_key; }
		}

		// If we didn't find a global or user key, return and don't setup and of the actions.
		if( false === $this->key ) { return; }
		
		wp_register_script( 'gp-machine-translate-js', plugins_url( 'gp-machine-translate.js', __FILE__ ), array( 'jquery', 'editor', 'gp-common' ) );
		
		// If the user has write permissions to the projects, add the bulk translate option to the projects menu.
		if( GP::$permission->user_can( wp_get_current_user(), 'write', 'project' ) ) {
			add_action( 'gp_project_actions', array( $this, 'gp_project_actions'), 10, 2 );
		}
		
		// Add the actions to handle adding the translate menu to the various parts of GlotPress.
		add_action( 'gp_pre_tmpl_load', array( $this, 'gp_pre_tmpl_load'), 10, 2);
		add_filter( 'gp_entry_actions', array( $this, 'gp_entry_actions' ), 10, 1 );
		add_action( 'gp_translation_set_bulk_action', array( $this, 'gp_translation_set_bulk_action'), 10, 1); 
		add_action( 'gp_translation_set_bulk_action_post', array( $this, 'gp_translation_set_bulk_action_post'), 10, 4);

		// We can't use the filter in the defaults route code because plugins don't load until after
		// it has already run, so instead add the routes directly to the global GP_Router object.
		GP::$router->add( "/bulk-translate/(.+?)", array( $this, 'bulk_translate' ), 'get' );
		GP::$router->add( "/bulk-translate/(.+?)", array( $this, 'bulk_translate' ), 'post' );
	}
	
	// Generate the HTML when a user views their profile.
	public function show_user_profile( $user ) {
		// Show and edit are virtually identical, so just call the edit function.
		$this->edit_user_profile( $user );
	}
	
	// Generate the HTML when a user profile is edited.  Note the $user parameter is a full user object for this function.
	public function edit_user_profile( $user ) {
		// Get the current user key from the WordPress options table.
		$user_key = get_user_meta( $user->ID, 'gp_machine_translate_key', true );
		
		// If the user cannot edit their profile, then don't show the settings.
		if ( !current_user_can( 'edit_user', $user_id ) ) { return false; }
?>
	<h3 id="gp-machine-translate"><?php _e('GP Machine Translate'); ?></h3>
	<table class="form-table">
		<tr>
			<th><label for="gp_machine_translate_user_key"><?php _e('User API Key');?></label></th>
			<td>
			<input type="text" id="gp_machine_translate_user_key" name="gp_machine_translate_user_key" size="40" value="<?php echo htmlentities( $user_key );?>">
			<p class="description"><?php printf( __( 'Enter the %s API key for this user.' ), $this->provider );?></p>
			</td>
		</tr>
	</table>
<?php		
		
	}
	
	// Once a profile has been updated, this function saves the settings to the WordPress options table.
	public function personal_options_update( $user_id ) {
		// If the user cannot edit their profile, then don't save the settings
		if ( !current_user_can( 'edit_user', $user_id ) ) { return false; }
		
		// Unlike the profile edit function, we only get the user id passed in as a parameter.
		update_user_meta( $user_id, 'gp_machine_translate_key', sanitize_text_field( $_POST['gp_machine_translate_user_key'] ) );
	}

	// Once a user profile has been edited, this function saves the settings to the WordPress options table.
	public function edit_user_profile_update( $user ) {
		// Since the profile and user edit code is identical, just call the profile update code.
		return $this->personal_options_update( $user );
	}
	
	// This function adds the "Machine Translate" option to the projects menu.
	public function gp_project_actions( $actions, $project ) {
		$actions[] .= gp_link_get( gp_url( 'bulk-translate/' . $project->slug), __('Machine Translate') . ' (' . $this->banners[$this->provider] . ')' );
		
		return $actions;
	}
	
	// This function is here as placeholder to support adding the bulk translate option to the router.
	// Without this placeholder there is a fatal error generated.
	public function before_request() {
	}

	// This function handles the actual bulk translate as passed in by the router for the projects menu.
	public function bulk_translate( $project_path ) {
		// First let's ensure we have decoded the project path for use later.
		$project_path = urldecode( $project_path );
		
		// Get the URL to the project for use later.
		$url = gp_url_project( $project_path );

		// If we don't have rights, just redirect back to the project.
		if( !GP::$permission->user_can( wp_get_current_user(), 'write', 'project' ) ) {
			wp_redirect( $url );
		}

		// Create a project class to use to get the project object.
		$project_class = new GP_Project;
		
		// Get the project object from the project path that was passed in.
		$project_obj = $project_class->by_path( $project_path );
		
		// Get the translations sets from the project ID.
		$translation_sets = GP::$translation_set->by_project_id( $project_obj->ID );

		// Loop through all the sets.
		foreach( $translation_sets as $set ) {
			// Create a template array to pass in to the worker function at the end of the loop.
			$bulk = array( 'action' => 'gtranslate', 'priority' => 0, 'row-ids' => array() );
			
			// Create a new GP_Translation object to use.
			$translation = new GP_Translation;
			
			// Get the strings for the current translation.
			$strings = $translation->for_translation( $project_obj, $set, null, array( 'status' => 'untranslated') );

			// Add the strings to the $bulk tempate we setup earlier.
			foreach( $strings as $string ) {
				$bulk['row-ids'][] .= $string->row_id;
			}
			
			// Get the locale we're working with.
			$locale = GP_Locales::by_slug( $set->locale );
			
			// Do the actual bulk translation.
			$this->gp_translation_set_bulk_action_post( $project_obj, $locale, $set, $bulk );
		}

		// Redirect back to the project home.
		wp_redirect( $url );
	}

	// This function is here as placeholder to support adding the bulk translate option to the router.
	// Without this placeholder there is a fatal error generated.
	public function after_request() {
	}

	// This function loads the javascript when required.
	public function gp_pre_tmpl_load( $template, $args ) {
		// If we don't have a translation key, just return without doing anything.
		if( ! $this->key ) {
			return;
		}
		
		// If we're not on the translation template, just return without doing anything.
		if ( 'translations' != $template ) {
			return;
		}

		// If the current locale isn't supported by the translation provider, just return without doing anything.
		if ( ! array_key_exists( $args['locale']->slug, $this->locales ) ) {
			return;
		}

		// Create options for the localization script.
		$options = array(
			'key'     => $this->key,
			'locale'  => $this->locales[$args['locale']->slug],
			'ajaxurl' => admin_url( 'admin-ajax.php'),
		);
		
		// Set the current Google code to the locale we're dealing with.
		$this->provider_code = $this->locales[$args['locale']->slug];

		// Enqueue the translation JavaScript and translate it.
		gp_enqueue_script( 'gp-machine-translate-js' );
		wp_localize_script( 'gp-machine-translate-js', 'gp_machine_translate', $options );
	}

	// This function adds the "Machine Translate" to the individual translation items.
	public function gp_entry_actions( $actions ) {
		// Make sure we are currently on a supported locale.
		if ( $this->provider_code ) {
			$actions[] = '<a href="#" class="gtranslate" tabindex="-1">' . __('Machine Translate') . '</a> (' . $this->banners[$this->provider] . ')';
		}

		return $actions;
	}

	// This function adds the "Machine Translate" to the bulk actions dropdown in the translation set list.
	public function gp_translation_set_bulk_action() {
		// Make sure we are currently on a supported locale.
		if ( $this->provider_code ) {
			echo '<option value="gtranslate">' . __('Machine Translate') . ' (' . $this->banners[$this->provider] . ')' . '</option>';
		}
	}

	// This function handles the actual bulk translation as passed in by the translation set list.
	public function gp_translation_set_bulk_action_post( $project, $locale, $translation_set, $bulk ) {
		// If we're not doing a bulk translation, just return.
		if ( 'gtranslate' != $bulk['action'] ) {
			return;
		}

		// If we don't have a translation key, just return.
		if( ! $this->key ) {
			return;
		}
		
		// Setup some variables to be used during the translation.
		$provider_errors = 0;
		$insert_errors = 0;
		$ok      = 0;
		$skipped = 0;

		$singulars = array();
		$original_ids = array();

		// Loop through each of the passed in strings and translate them.
		foreach ( $bulk['row-ids'] as $row_id ) {
			// check to see if there is a '-' in the $row_id, if not, we should skip it.
			// gp_in() returns true if the search string is found in the haystack.
			if ( gp_in( '-', $row_id ) ) {
				$skipped++;
				continue;
			}

			// Split the $row_id by '-' and get the first one (which will be the id of the original).
			$original_id = gp_array_get( explode( '-', $row_id ), 0 );
			// Get the original based on the above id.
			$original    = GP::$original->get( $original_id );

			// If there is no original or it's a plural, skip it.
			if ( ! $original || $original->plural ) {
				$skipped++;
				continue;
			}

			// Add the original to the queue to translate.
			$singulars[] = $original->singular;
			$original_ids[] = $original_id;
		}

		// Translate all the originals that we found.
		$results = $this->translate_batch( $locale, $singulars );

		// Did we get an error?
		if ( is_wp_error( $results ) ) {
			error_log( print_r( $results, true ) );
			gp_notice_set( $results->get_error_message(), 'error' );
			return;

		}

		// Merge the results back in to the original id's and singulars, this will create an array like ($items = array( array( id, single, result), array( id, single, result), ... ).
		$items = gp_array_zip( $original_ids, $singulars, $results );

		// If we have no items, something went wrong and stop processing.
		if ( ! $items ) {
			return;
		}

		// Loop through the items and store them in the database.
		foreach ( $items as $item ) {
			// Break up the item back in to individual components.
			list( $original_id, $singular, $translation ) = $item;

			// Did we get an error?
			if ( is_wp_error( $translation ) ) {
				$provider_errors++;
				error_log( $translation->get_error_message() );
				continue;
			}

			// Build a data array to store
			$data = compact( 'original_id' );
			$data['user_id'] = get_current_user_id();
			$data['translation_set_id'] = $translation_set->id;
			$data['translation_0'] = $translation;
			$data['status'] = 'fuzzy';
			$data['warnings'] = GP::$translation_warnings->check( $singular, null, array( $translation ), $locale );

			// Insert the item in to the database.
			$inserted = GP::$translation->create( $data );
			$inserted? $ok++ : $insert_errors++;
		}

		// Did we get an error?  If so let's let the user know about them.
		if ( $provider_errors > 0 || $insert_errors > 0 ) {
			// Create a message array to use later.
			$message = array();

			// Did we have any strings translated successfully?
			if ( $ok ) {
				$message[] = sprintf( __('Added: %d.' ), $ok );
			}

			// Did we have any provider errors.
			if ( $provider_errors ) {
				$message[] = sprintf( __('Error from %s: %d.' ), $this->provider, $provider_errors );
			}

			// Did we have any errors when we saved everything to the database?
			if ( $insert_errors ) {
				$message[] = sprintf( __('Error adding: %d.' ), $insert_errors );
			}

			// Did we skip any items?
			if ( $skipped ) {
				$message[] = sprintf( __('Skipped: %d.' ), $skipped );
			}

			// Create a message string and add it to the GlotPress notices.
			gp_notice_set( implode( '', $message ), 'error' );
		}
		else {
			// If we didn't get any errors, then we just need to let the user know how many translations were added.
			gp_notice_set( sprintf( __('%d fuzzy translation from Machine Translate were added.' ), $ok ) );
		}
	}

	public function translate_batch( $locale, $strings ) {
		switch( $this->provider ) {
			case 'Google Translate':
				return $this->google_translate_batch( $locale, $strings );
				
				break;
			case 'Microsoft Translator':
				return $this->microsoft_translate_batch( $locale, $strings );
				
				break;
			case 'transltr.org':
				return $this->transltr_translate_batch( $locale, $strings );
			
				break;
			case 'Yandex.Translate':
				return $this->yandex_translate_batch( $locale, $strings );
				
				break;
		}
	}
	
	private function transltr_translate_batch( $locale, $strings ) {
		// If we don't have a supported Yandex translation code, throw an error.
		if ( ! array_key_exists( $locale, $this->locales ) ) {
			return new WP_Error( 'gp_machine_translate', sprintf( "The locale %s isn't supported by %s.", $locale->slug, $this->provider ) );
		}

		// If we don't have any strings, throw an error.
		if ( count( $strings ) == 0 ) {
			return new WP_Error( 'gp_machine_translate', "No strings found to translate." );
		}

		// This is the URL of the transltr.org API.
		$base_url = 'http://www.transltr.org/api/translate?from=en&to=' . urlencode( $this->locales[$locale] ) . '&text=';

		// Setup an temporary array to use to process the response.
		$translations = array();

		// Loop through the stings and add them to the $url as a query string.
		foreach ( $strings as $string ) {
			$url = $base_url . urlencode( $string );

			// Get the response from translte.org.
			$response = wp_remote_get( $url );

			// Did we get an error?
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Decode the response from translte.org.
			$json = json_decode( wp_remote_retrieve_body( $response ) );

			// If something went wrong with the response from translte.org, throw an error.
			if ( ! $json ) {
				return new WP_Error( 'gp_machine_translate', 'Error decoding JSON from translte.org Translate.' );
			}

			if ( isset( $json->error ) ) {
				return new WP_Error( 'gp_machine_translate', sprintf( 'Error auto-translating: %1$s', $json->error->errors[0]->message ) );
			}
			
			$translations[] = $this->google_translate_fix( $json->translationText );
		}

		// Return the results.
		return $translations;
	}
	
	private function microsoft_translate_batch( $locale, $strings ) {
		// If we don't have a supported Microsoft Translator translation code, throw an error.
		if ( ! array_key_exists( $locale, $this->locales ) ) {
			return new WP_Error( 'gp_machine_translate', sprintf( "The locale %s isn't supported by %s.", $locale->slug, $this->provider ) );
		}

		// If we don't have any strings, throw an error.
		if ( count( $strings ) == 0 ) {
			return new WP_Error( 'gp_machine_translate', "No strings found to translate." );
		}

		
		include( dirname( __FILE__ ) . '/vendor/autoload.php' );
		
		$config = array(
			'clientID' => $this->client_id,
			'clientSecret' => $this->key,
		);
		$t = new \MicrosoftTranslator\Translate( $config );
		$translation = $t->translate( $strings, $this->locales[$locale], 'en' );
		
		return $translation;

	}

	private function yandex_translate_batch( $locale, $strings ) {
		// If we don't have a supported Yandex translation code, throw an error.
		if ( ! array_key_exists( $locale, $this->locales ) ) {
			return new WP_Error( 'gp_machine_translate', sprintf( "The locale %s isn't supported by %s.", $locale->slug, $this->provider ) );
		}

		// If we don't have any strings, throw an error.
		if ( count( $strings ) == 0 ) {
			return new WP_Error( 'gp_machine_translate', "No strings found to translate." );
		}

		// This is the URL of the Yandex API.
		$url = 'https://translate.yandex.net/api/v1.5/tr.json/translate?key=' . $this->key . '&lang=en-' . urlencode( $this->locales[$locale] );

		// Loop through the stings and add them to the $url as a query string.
		foreach ( $strings as $string ) {
			$url .= '&text=' . urlencode( $string );
		}

		// Get the response from Yandex.
		$response = wp_remote_get( $url );

		// Did we get an error?
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Decode the response from Yandex.
		$json = json_decode( wp_remote_retrieve_body( $response ) );

		// If something went wrong with the response from Yandex, throw an error.
		if ( ! $json ) {
			return new WP_Error( 'gp_machine_translate', 'Error decoding JSON from Yandex Translate.' );
		}

		if ( isset( $json->error ) ) {
			return new WP_Error( 'gp_machine_translate', sprintf( 'Error auto-translating: %1$s', $json->error->errors[0]->message ) );
		}

		// Setup an temporary array to use to process the response.
		$translations = array();

		// If the translations have been return as a single entry, make it an array so it's easier to process later.
		if ( ! is_array( $json->text ) ) {
			$json->text = array( $json->text );
		}

		// Merge the originals and translations arrays.
		$items = gp_array_zip( $strings, $json->text );

		// If there are no items, throw an error.
		if ( ! $items ) {
			return new WP_Error( 'gp_machine_translate', 'Error merging arrays' );
		}
		
		// Loop through the items and clean up the responses.
		foreach ( $items as $item ) {
			list( $string, $translation ) = $item;

			$translations[] = $this->google_translate_fix( $translation );
		}

		// Return the results.
		return $translations;
	}
	
	// This function contacts Google and translate a set of strings.
	private function google_translate_batch( $locale, $strings ) {
		// If we don't have a supported Google translation code, throw an error.
		if ( ! array_key_exists( $locale->slug, $this->locales ) ) {
			return new WP_Error( 'gp_machine_translate', sprintf( "The locale %s isn't supported by %s.", $locale->slug, $this->provider ) );
		}

		// If we don't have any strings, throw an error.
		if ( count( $strings ) == 0 ) {
			return new WP_Error( 'gp_machine_translate', "No strings found to translate." );
		}
		
		// This is the URL of the Google API.
		$url = 'https://www.googleapis.com/language/translate/v2?key=' . $this->key . '&source=en&target=' . urlencode( $this->locales[$locale->slug] );

		// Loop through the stings and add them to the $url as a query string.
		foreach ( $strings as $string ) {
			$url .= '&q=' . urlencode( $string );
		}

		// If we just have a single string, add an extra q= to the end so Google things we're doing multiple strings.
		if ( count( $strings ) == 1 ) {
			$url .= '&q=';
		}

		// Get the response from Google.
		$response = wp_remote_get( $url );

		// Did we get an error?
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Decode the response from Google.
		$json = json_decode( wp_remote_retrieve_body( $response ) );

		// If something went wrong with the response from Google, throw an error.
		if ( ! $json ) {
			return new WP_Error( 'gp_machine_translate', 'Error decoding JSON from Google Translate.' );
		}

		if ( isset( $json->error ) ) {
			return new WP_Error( 'gp_machine_translate', sprintf( 'Error auto-translating: %1$s', $json->error->errors[0]->message ) );
		}

		// Setup an temporary array to use to process the response.
		$translations = array();

		// If the translations have been return as a single entry, make it an array so it's easier to process later.
		if ( ! is_array( $json->data->translations ) ) {
			$json->data->translations = array( $json->data->translations );
		}

		// Merge the originals and translations arrays.
		$items = gp_array_zip( $strings, $json->data->translations );

		// If there are no items, throw an error.
		if ( ! $items ) {
			return new WP_Error( 'gp_machine_translate', 'Error merging arrays' );
		}

		// Loop through the items and clean up the responses.
		foreach ( $items as $item ) {
			list( $string, $translation ) = $item;

			$translations[] = $this->google_translate_fix( $translation->translatedText );
		}

		// Return the results.
		return $translations;
	}

	// This function cleans up the results from Google.
	private function google_translate_fix( $string ) {
		$string = preg_replace_callback( '/% (s|d)/i', function ($m) { return '"%".strtolower($m[1])'; }, $string );
		$string = preg_replace_callback( '/% (\d+) \$ (s|d)/i', function ($m) { return '"%".$m[1]."\\$".strtolower($m[2])'; }, $string );
		
		return $string;
	}
	
	// This function adds the admin settings page to WordPress.
	public function admin_menu() {
		add_options_page( __('GP Machine Translate'), __('GP Machine Translate'), 'manage_options', basename( __FILE__ ), array( $this, 'admin_page' ) );
	}
	
	// This function displays the admin settings page in WordPress.
	public function admin_page() {
		// If the current user can't manage options, display a message and return immediately.
		if( ! current_user_can( 'manage_options' ) ) { _e('You do not have permissions to this page!'); return; }
		
		// If the user has saved the settings, commit them to the database.
		if( array_key_exists( 'save_gp_machine_transalate', $_POST ) ) {
			// Flush the global key, in case the user is removing the API key.
			$this->key = '';
			
			// If the API key value is being saved, store it in the global key setting.
			if( array_key_exists( 'gp_machine_translate_key', $_POST ) ) {
				// Make sure to sanitize the data before saving it.
				$this->key = sanitize_text_field( $_POST['gp_machine_translate_key'] );
			}	
			
			// If the client ID value is being saved, store it in the global key setting.
			if( array_key_exists( 'gp_machine_translate_client_id', $_POST ) ) {
				// Make sure to sanitize the data before saving it.
				$this->client_id = sanitize_text_field( $_POST['gp_machine_translate_client_id'] );
			}	

			$provider = $_POST['gp_machine_translate_provider'];
			
			if( $provider != '*Select*' && in_array( $provider, $this->providers ) ) {
				update_option( 'gp_machine_translate_provider', $provider );
				$this->provider = $provider;
			}
			
			// Update the option in the database.
			update_option( 'gp_machine_translate_key', $this->key );
			
			// Update the client ID>
			update_option( 'gp_machine_translate_client_id', $this->client_id );
		}
		
	?>	
<div class="wrap">
	<h2><?php _e('GP Machine Translate Settings');?></h2>

	<form method="post" action="options-general.php?page=gp-machine-translate.php" >	
		<table class="form-table">
			<tr>
				<th><label for="gp_machine_translate_provider"><?php _e('Translation Provider');?></label></th>
				<td>
				<select id="gp_machine_translate_provider" name="gp_machine_translate_provider">
					<option value="">*Select*</option>
					<option value="Google Translate"<?php if( $this->provider == 'Google Translate' ) { echo " selected"; }?>>Google Translate</option>
					<option value="Microsoft Translator"<?php if( $this->provider == 'Microsoft Translator' ) { echo " selected"; }?>>Microsoft Translator</option>
					<option value="transltr.org"<?php if( $this->provider == 'transltr.org' ) { echo " selected"; }?>>transltr.org</option>
					<option value="Yandex.Translate"<?php if( $this->provider == 'Yandex.Translate' ) { echo " selected"; }?>>Yandex.Translate</option>
				</select>
				<p class="description"><?php _e('Select the translation provider to use.');?></p>
				</td>
			</tr>
			<tr>
				<th><label for="gp_machine_translate_key"><?php _e('Global API Key');?></label></th>
				<td>
				<input type="text" id="gp_machine_translate_key" name="gp_machine_translate_key" size="40" value="<?php echo htmlentities( $this->key );?>">
				<p class="description"><?php _e('Enter the API key for all users (leave blank to disable, per user API keys will still function).');?></p>
				</td>
			</tr>
			<tr>
				<th><label for="gp_machine_translate_client_id"><?php _e('Client ID');?></label></th>
				<td>
				<input type="text" id="gp_machine_translate_client_id" name="gp_machine_translate_client_id" size="40" value="<?php echo htmlentities( $this->client_id );?>">
				<p class="description"><?php _e('Enter the client ID if use Microsoft Translator.');?></p>
				</td>
			</tr>
		</table>
		
		<?php submit_button( __('Save'), 'primary', 'save_gp_machine_transalate' ); ?>
		
	</form>
	
</div>
<?php		
	}
	
	private function upgrade() {
		GLOBAL $wpdb;
		
		// If the old google key exists, update it to the new option name and remove it.
		// On the next upgrade this code will not run.
		// To be removed in a future version once we're well past version 0.7.
		if( get_option( 'gp_google_translate_key', false ) !== false ) {
			// Rename the global translation key name.
			update_option( 'gp_machine_translate_key', get_option( 'gp_google_translate_key', false ) );
			delete_option( 'gp_google_translate_key' );
		}
		
		// Rename the per use translation key name.  We can't do this in the "if" above as the global key
		// may be set to blank but user keys may still exist, so we have to do this on each upgrade.
		// To be removed in a future version once we're well past version 0.7.
		$wpdb->query( "UPDATE {$wpdb->usermeta} SET `meta_key`='gp_machine_translate_key' WHERE `meta_key`='gp_google_translate_key';" );
			
		// Update the version option to the current version so we don't run the upgrade process again.
		update_option( 'gp_machine_translate_version', $this->version );
	}
}

// Add an action to WordPress's init hook to setup the plugin.  Don't just setup the plugin here as the GlotPress plugin may not have loaded yet.
add_action( 'gp_init', 'gp_machine_translate_init' );

include_once( 'ajax.php' );

// This function creates the plugin.
function gp_machine_translate_init() {
	GLOBAL $gp_machine_translate;
	
	$gp_machine_translate = new GP_Machine_Translate;
}