<?php

	/**
	 * @package subsectionmanager
	 */
	/**
	 * Subsection Manager Extension
	 */

	Class extension_subsectionmanager extends Extension {

		public function __construct(Array $args){
			parent::__construct($args);
			
			// Include Stage
			try {
				if((include_once(EXTENSIONS . '/subsectionmanager/lib/stage/class.stage.php')) === FALSE) {
					throw new Exception();
				}
			}
			catch(Exception $e) {
			    throw new SymphonyErrorPage(__('Please make sure that the Stage submodule is initialised and available at %s.', array('<code>' . EXTENSIONS . '/subsectionmanager/lib/stage/</code>')) . '<br/><br/>' . __('It\'s available at %s.', array('<a href="https://github.com/nilshoerrmann/stage">github.com/nilshoerrmann/stage</a>')), __('Stage not found'));
			}
		}

		public function about() {
			return array(
				'name' => 'Subsection Manager',
				'type' => 'Field, Interface',
				'version' => '1.2dev',
				'release-date' => false,
				'author' => array(
					'name' => 'Nils Hörrmann',
					'website' => 'http://nilshoerrmann.de',
					'email' => 'post@nilshoerrmann.de'
				),
				'description' => 'Subsection Management for Symphony.'
			);
		}

		public function getSubscribedDelegates(){
			return array(
				array(
					'page' => '/administration/',
					'delegate' => 'AdminPagePreGenerate',
					'callback' => '__appendAssets'
				),
				array(
					'page' => '/publish/new/',
					'delegate' => 'EntryPostNew',
					'callback' => '__saveSortOrder'
				),
				array(
					'page' => '/publish/edit/',
					'delegate' => 'EntryPostEdit',
					'callback' => '__saveSortOrder'
				),
				array(
					'page' => '/publish/',
					'delegate' => 'Delete',
					'callback' => '__deleteSortOrder'
				),
				array(
					'page' => '/backend/',
					'delegate' => 'AppendPageAlert', 
					'callback' => '__upgradeMediathek'
				),
				array(
					'page' => '/frontend/',
					'delegate' => 'DataSourceEntriesBuilt', 
					'callback' => '__fetchSubsectionElements'
				)
			);
		}

		/**
		 * Append assets to the page head
		 *
		 * @param object $context
 		 */
 		public function __appendAssets($context) {
			$callback = Symphony::Engine()->getPageCallback();
			
			// Append skripts and styles for field settings pane
			if($callback['driver'] == 'blueprintssections' && is_array($callback['context'])) {
				Administration::instance()->Page->addScriptToHead(URL . '/extensions/subsectionmanager/assets/subsectionmanager.settings.js', 100, false);
			}

			// Append styles for publish area
			if($callback['driver'] == 'publish' && $callback['context']['page'] == 'index') {
				Administration::instance()->Page->addStylesheetToHead(URL . '/extensions/subsectionmanager/assets/subsectionmanager.index.publish.css', 'screen', 100, false);
			}

			// Append styles for subsection display
			if($callback['driver'] == 'publish' && $callback['context']['page'] != 'index') {
				Administration::instance()->Page->addStylesheetToHead(URL . '/extensions/subsectionmanager/assets/subsection.publish.css', 'screen', 101, false);
			}
		}

		/**
		 * Save sort order
		 *
		 * @param object $context
		 */
		public function __saveSortOrder($context) {
		
			if(!is_null($context['fields']['sort_order'])) {
			
				// Delete current sort order
				$entry_id = $context['entry']->get('id');
				Symphony::Database()->query(
					"DELETE FROM `tbl_fields_stage_sorting` WHERE `entry_id` = '$entry_id'"
				);
				
				// Add new sort order
				foreach($context['fields']['sort_order'] as $field_id => $value) {
					$entries = explode(',', $value);
					$order = array();
					foreach($entries as $entry) {
						$order[] = intval($entry);
					}
					Symphony::Database()->query(
						"INSERT INTO `tbl_fields_stage_sorting` (`entry_id`, `field_id`, `order`) VALUES ('$entry_id', '$field_id', '" . implode(',', $order) . "')"
					);
				}
			}
		}

		/**
		 * Delete sort order of the field
		 *
		 * @param object $context
		 */
		public function __deleteSortOrder($context) {
			// DELEGATE NOT WORKING:
			// http://github.com/symphony/symphony-2/issues#issue/108
		}
		
		/**
		 * Upgrade Mediathek fields to make use of this extension
		 */
		public function __upgradeMediathek() {

			// Do not use Administration::instance() in this context, see:
			// http://github.com/nilshoerrmann/subsectionmanager/issues#issue/27
			$callback = $this->_Parent->getPageCallback();

			// Append upgrade notice
			if($callback['driver'] == 'systemextensions') {
			
				require_once(TOOLKIT . '/class.extensionmanager.php');
				$ExtensionManager = new ExtensionManager(Administration::instance());

				// Check if Mediathek field is installed
				$mediathek = $ExtensionManager->fetchStatus('mediathek');
				if($mediathek == EXTENSION_ENABLED || $mediathek == EXTENSION_DISABLED) {
				
					// Append upgrade notice to page
					Symphony::Engine()->Page->Alert = new Alert(
						__('You are using Mediathek and Subsection Manager simultaneously.') . ' <a href="http://' . DOMAIN . '/symphony/extension/subsectionmanager/">' . __('Upgrade') . '?</a> <a href="http://' . DOMAIN . '/symphony/extension/subsectionmanager/uninstall/mediathek">' . __('Uninstall Mediathek') . '</a> <a href="http://' . DOMAIN . '/symphony/extension/subsectionmanager/uninstall/subsectionmanager">' . __('Uninstall Subsection Manager') . '</a>', 
						Alert::ERROR
					);
				}
			}
		}
		
		public function __fetchSubsectionElements(&$context) {
			$entryManager = new EntryManager(Symphony::Engine());
		
			// Create storage
			if(!isset(Symphony::ExtensionManager()->SubsectionManager)) {
				Symphony::ExtensionManager()->SubsectionManager = array();
			}
			
		/*-----------------------------------------------------------------------*/
		
			// Fetch subsection fields
			$section_id = $context['datasource']->getSource();
			$subsectionmanagers = Symphony::Database()->fetch(
				"SELECT `id`, `element_name` 
				FROM `tbl_fields` 
				WHERE `parent_section` = " . $section_id . " 
				AND `type` = 'subsectiontabs' 
				OR `type` = 'subsectionmanager'"
			);
			
			// Associate id and name
			$subsection_fields = array();
			foreach($subsectionmanagers as $manager) {
				$subsection_fields[$manager['id']] = $manager['element_name'];
			}

			// Parse field modes
			$subsections = array();
			$subsection_ids = array();
			foreach($context['datasource']->dsParamINCLUDEDELEMENTS as $index => $included) {
				$fields = explode(': ', $included, 2);
				
				// Get subsection fields
				$field_id = array_search($fields[0], $subsection_fields);
				if($field_id) {
					
					// Get subsection id
					$subsection_ids[$field_id] = Symphony::Database()->fetchVar('subsection_id', 0, 
						"SELECT `subsection_id` 
						FROM `tbl_fields_" . ($fields[0] == 'subsection-tabs' ? 'subsectiontabs' : 'subsectionmanager') . "` 
						WHERE `field_id` LIKE '" . $field_id . "'
						LIMIT 1"
					);

					// Get field id an mode
					$components = explode(': ', $fields[1], 2);
					$subfield_id = $entryManager->fieldManager->fetchFieldIDFromElementName($components[0], $subsection_ids[$field_id]);

					// Store field data
					Symphony::ExtensionManager()->SubsectionManager['fields'][$field_id][$subfield_id] = $components[1];
	
					// Set a single field call for subsection fields
					unset($context['datasource']->dsParamINCLUDEDELEMENTS[$index]);
					if(!in_array($fields[0], $context['datasource']->dsParamINCLUDEDELEMENTS)) {
						$context['datasource']->dsParamINCLUDEDELEMENTS[$index] = $fields[0];
					}
				}
			}
				
		/*-----------------------------------------------------------------------*/
			
			// Get entry data
			$parent_entries = array();
			foreach($context['entries']['records'] as $entry) {
				$parent_entries[] = $entry->getData();
			}
			
			// Fetch subsection entries
			Symphony::ExtensionManager()->SubsectionManager['entries'] = array();
			foreach($subsection_fields as $id => $name) {
				
				// Get ids
				$entry_id = array();
				foreach($parent_entries as $entry) {
					$entry_id = array_merge((array)$entry[$id]['relation_id'], $entry_id);			
				}
				
				// Check for already loaded entries
				$entry_id = array_diff($entry_id, array_keys(Symphony::ExtensionManager()->SubsectionManager['entries']));
				
				// Fetch entries
				if(!empty($entry_id) && !empty($subsection_ids[$id])) {
					$entries = $entryManager->fetch($entry_id, $subsection_ids[$id]);
					
					// Store entries
					foreach($entries as $entry) {
						Symphony::ExtensionManager()->SubsectionManager['entries'][$entry->get('id')] = $entry;
					}
				}
			}
		}

		/**
		 * Any logic that assists this extension in being installed such as
		 * table creation, checking for dependancies etc.
		 *
		 * @see toolkit.ExtensionManager#install
		 * @return boolean
		 *  True if the install completely successfully, false otherwise
		 */
		public function install() {
			$status = array();
		
			// Create Subsection Manager
			$status[] = Symphony::Database()->query(
				"CREATE TABLE IF NOT EXISTS `tbl_fields_subsectionmanager` (
					`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
					`field_id` int(11) unsigned NOT NULL,
					`subsection_id` VARCHAR(255) NOT NULL,
					`filter_tags` text,
					`caption` text,
					`droptext` text,
					`included_fields` text,
					`allow_multiple` tinyint(1) default '0',
					`show_preview` tinyint(1) default '0',
			  		PRIMARY KEY  (`id`),
			  		KEY `field_id` (`field_id`)
				)"
			);
			
			// Create Subsection Tabs
			$status[] = Symphony::Database()->query(
				"CREATE TABLE `sym_fields_subsectiontabs` (
					`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
					`field_id` int(11) unsigned NOT NULL,
					`subsection_id` varchar(255) NOT NULL,
					`static_tabs` varchar(255) DEFAULT NULL,
					`allow_dynamic_tabs` tinyint(1) NOT NULL DEFAULT '1',
				 	PRIMARY KEY (`id`),
			  		KEY `field_id` (`field_id`)
				)"
			);
			
			// Create stage
			$status[] = Stage::install();
			
			// Report status
			if(in_array(false, $status, true)) {
				return false;
			}
			else {
				return true;
			}
		}

		/**
		 * Logic that should take place when an extension is to be been updated
		 * when a user runs the 'Enable' action from the backend. The currently
		 * installed version of this extension is provided so that it can be
		 * compared to the current version of the extension in the file system.
		 * This is commonly done using PHP's version_compare function. Common
		 * logic done by this method is to update differences between extension
		 * tables.
		 *
		 * @see toolkit.ExtensionManager#update
		 * @param string $previousVersion
		 *  The currently installed version of this extension from the
		 *  tbl_extensions table. The current version of this extension is
		 *  provided by the about() method.
		 * @return boolean
		 */
		public function update($previousVersion) {
			$status = array();
		
			// Update beta installs
			if(version_compare($previousVersion, '1.0', '<')) {
				
				// Install missing tables
				$this->install();
				
				// Add context row and return status
				$context = Symphony::Database()->fetchVar('Field', 0, "SHOW COLUMNS FROM `tbl_fields_subsectionmanager` LIKE 'context'");
				if(!$context) {
					$status[] = Symphony::Database()->query(
						"ALTER TABLE `tbl_fields_stage` ADD `context` varchar(255) default NULL"
					);
				}
				
			}

			// Update 1.0 installs
			if(version_compare($previousVersion, '1.1', '<')) {
			
				// Add droptext column
				$droptext = Symphony::Database()->fetchVar('Field', 0, "SHOW COLUMNS FROM `tbl_fields_subsectionmanager` LIKE 'droptext'");
				if(!$droptext) {
					$status[] = Symphony::Database()->query(
						"ALTER TABLE `tbl_fields_subsectionmanager` ADD `droptext` text default NULL"
					);
				}
				
				// Create stage tables
				$status[] = Stage::install();
				
				// Fetch sort orders
				$table = Symphony::Database()->fetch("SHOW TABLES LIKE 'tbl_fields_subsectionmanager_sorting'");
				if(!empty($table)) {
					$sortings = Symphony::Database()->fetch("SELECT * FROM tbl_fields_subsectionmanager_sorting LIMIT 1000");
					
					// Move sort orders to stage table
					if(is_array($sortings)) {
						foreach($sortings as $sorting) {
							$status[] = Symphony::Database()->query(
								"INSERT INTO tbl_fields_stage_sorting (`entry_id`, `field_id`, `order`, `context`) VALUES (" . $sorting['entry_id'] . ", " . $sorting['field_id'] . ", '" . $sorting['order'] . "', 'subsectionmanager')"
							);
						}
					}
	
					// Drop old sorting table
					$status[] = Symphony::Database()->query("DROP TABLE IF EXISTS `tbl_fields_subsectionmanager_sorting`");			
				}
				
				// Add section associations data to sections_association table
				$field_ids = array();
				$associations = array();
				$field_ids = Symphony::Database()->fetchCol('field_id', "
					SELECT
						f.field_id
					FROM
						`tbl_fields_subsectionmanager` AS f
				");
				if(!empty($field_ids)) {
					foreach ($field_ids as $id) {
						$parent_section_id = Symphony::Database()->fetchVar('parent_section', 0, "
							SELECT
								f.parent_section
							FROM
								`tbl_fields` AS f
							WHERE
								f.id = '{$id}'
							LIMIT 1
						");
						$child_section_id = Symphony::Database()->fetchVar('subsection_id', 0, "
							SELECT
								f.subsection_id
							FROM
								`tbl_fields_subsectionmanager` AS f
							WHERE
								f.field_id = '{$id}'
							LIMIT 1
						");
						$associations[] = array(
							'parent_section_id' => $parent_section_id,
							'parent_section_field_id' => $id,
							'child_section_id' => $child_section_id,
							'child_section_field_id' => $id,
							'hide_association' => 'yes',
						);
					}
				}
				if(!empty($associations)) {
					foreach ($associations as $association) {
						$status[] = Symphony::Database()->insert($association, 'tbl_sections_association');
					}
				}
			}
			
			if(version_compare($previousVersion, '1.2', '<')) {
				$status[] = Symphony::Database()->query(
					"CREATE TABLE `sym_fields_subsectiontabs` (
						`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
						`field_id` int(11) unsigned NOT NULL,
						`subsection_id` varchar(255) NOT NULL,
						`static_tabs` varchar(255) DEFAULT NULL,
						`allow_dynamic_tabs` tinyint(1) NOT NULL DEFAULT '1',
					 	PRIMARY KEY (`id`),
				  		KEY `field_id` (`field_id`)
					)"
				);
			}
			
			
			// Report status
			if(in_array(false, $status, true)) {
				return false;
			}
			else {
				return true;
			}
		}

		/**
		 * Any logic that should be run when an extension is to be uninstalled
		 * such as the removal of database tables.
		 *
		 * @see toolkit.ExtensionManager#uninstall
		 * @return boolean
		 */
		public function uninstall() {
		
			// Drop related entries from stage tables
			Symphony::Database()->query("DELETE FROM `tbl_fields_stage` WHERE `context` = 'subsectionmanager'");
			Symphony::Database()->query("DELETE FROM `tbl_fields_stage_sorting` WHERE `context` = 'subsectionmanager'");

			// Drop tables
			Symphony::Database()->query("DROP TABLE IF EXISTS `tbl_fields_subsectionmanager`");
			
			// Maintenance		
			Symphony::Database()->query("DROP TABLE IF EXISTS `tbl_fields_subsectionmanager_sorting`");			
		}
		
	}