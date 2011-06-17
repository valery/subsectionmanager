<?php

	/**
	 * @package subsectionmanager
	 */
	/**
	 * Subsection Manager Extension
	 */

	Class extension_subsectionmanager extends Extension {
		
		/**
		 * Private instance of Entry Manager
		 */		
		private static $entryManager;
	
		/**
		 * Storage for subsection entries
		 */
		public static $storage = array(
			'fields' => array(),
			'entries' => array()
		);

		/**
		 * @see http://symphony-cms.com/learn/api/2.2/toolkit/extension/#__construct
		 */
		public function __construct(Array $args){
			parent::__construct($args);
			
			// Include Stage
			if(!class_exists('Stage')) {
				try {
					if((include_once(EXTENSIONS . '/subsectionmanager/lib/stage/class.stage.php')) === FALSE) {
						throw new Exception();
					}
				}
				catch(Exception $e) {
				    throw new SymphonyErrorPage(__('Please make sure that the Stage submodule is initialised and available at %s.', array('<code>' . EXTENSIONS . '/subsectionmanager/lib/stage/</code>')) . '<br/><br/>' . __('It\'s available at %s.', array('<a href="https://github.com/nilshoerrmann/stage">github.com/nilshoerrmann/stage</a>')), __('Stage not found'));
				}
			}
		}

		/**
		 * @see http://symphony-cms.com/learn/api/2.2/toolkit/extension/#about
		 */
		public function about() {
			return array(
				'name' => 'Subsection Manager',
				'type' => 'Field, Interface',
				'version' => '2.0dev.5',
				'release-date' => false,
				'author' => array(
					'name' => 'Nils Hörrmann',
					'website' => 'http://nilshoerrmann.de',
					'email' => 'post@nilshoerrmann.de'
				),
				'description' => 'Subsection Management for Symphony.'
			);
		}

		/**
		 * @see http://symphony-cms.com/learn/api/2.2/toolkit/extension/#getSubscribedDelegates
		 */
		public function getSubscribedDelegates(){
			return array(
				array(
					'page' => '/backend/',
					'delegate' => 'AdminPagePreGenerate',
					'callback' => '__appendAssets'
				),
				array(
					'page' => '/backend/',
					'delegate' => 'AppendPageAlert', 
					'callback' => '__upgradeMediathek'
				),
				array(
					'page' => '/frontend/',
					'delegate' => 'DataSourceEntriesBuilt', 
					'callback' => '__prepareSubsection'
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
				Administration::instance()->Page->addStylesheetToHead(URL . '/extensions/subsectionmanager/assets/subsectionmanager.settings.css', 'screen', 101, false);
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
		
		/**
		 * Fetch all subsection elements included in a data source and 
		 * join modes into a single call to `appendFormattedElement()`.
		 * Preprocess all subsection entry for performance reasons.
		 *
		 * @see http://symphony-cms.com/learn/api/2.2/delegates/#DataSourceEntriesBuilt
		 */
		public function __prepareSubsection(&$context) {
			$parent = get_parent_class($context['datasource']);
			
			// Initialise Entry Manager
			if(empty($this->entryManager)) {
				self::$entryManager = new EntryManager(Symphony::Engine());
			}

			// Default Data Source
			if($parent == 'DataSource') {
				$this->__parseSubsectionFields(
					$context['datasource']->dsParamINCLUDEDELEMENTS, 
					$context['datasource']->dsParamROOTELEMENT, 
					$context['datasource']
				);
			}
			
			// Union Data Source
			elseif($parent == 'UnionDatasource') {
				foreach($context['datasource']->datasources as $datasource) {
					$this->__parseSubsectionFields(
						$datasource['datasource']->dsParamINCLUDEDELEMENTS, 
						$datasource['datasource']->dsParamROOTELEMENT, 
						$datasource['datasource']
					);
				}
			}

			// Preload entries
			self::preloadSubsectionEntries($context['entries']['records']);
		}
		
		/**
		 * Parse data source and extract subsection fields
		 *
		 * @param DataSource $datasource
		 *	The data source class to parse
		 */
		private function __parseSubsectionFields($fields, $context, $datasource = null) {

			// Get source
			$section = 0;
			if(isset($datasource)) {
				if(is_numeric($datasource)) {
					$section = $datasource;
				}
				else if(method_exists($datasource, 'getSource')) {
					$section = $datasource->getSource();
				}
			}

			// Parse included elements
			if(!empty($fields)) {
				foreach($fields as $index => $included) {
					list($subsection, $field, $remainder) = explode(': ', $included, 3);

					// Fetch fields
					if($field != 'formatted' && $field != 'unformatted' && !empty($field)) {

						// Get field id and mode
						if($remainder == 'formatted' || $remainder == 'unformatted' || empty($remainder)) {
							$this->__fetchFields($section, $context, $subsection, $field, $remainder);
						}
						else {
							$subsection_id = $this->__fetchFields($section, $context, $subsection, $field, "{$context}/{$subsection}");
							$this->__parseSubsectionFields(array($field . ': ' . $remainder), "{$context}/{$subsection}", $subsection_id);
						}
	
						// Set a single field call for subsection fields
						if(is_object($datasource)) {
							unset($datasource->dsParamINCLUDEDELEMENTS[$index]);
	
							$storage = $subsection . ': ' . $context;
							if(!in_array($storage, $datasource->dsParamINCLUDEDELEMENTS)) {
								$datasource->dsParamINCLUDEDELEMENTS[$index] = $storage;
							}
						}
					}
				}
			}
		}
		
		private function __fetchFields($section, $context, $subsection, $field, $mode = '') {
			// Section context
			if($section !== 0) {
				$section = " AND t2.`parent_section` = '".intval($section)."' ";				
			}
			else {
				$section = '';
			}

			$subsection = Symphony::Database()->cleanValue($subsection);
			
			// Get id
			$id = Symphony::Database()->fetch( 
				"(SELECT t1.`subsection_id`, t1.field_id
					FROM `tbl_fields_subsectionmanager` AS t1 
					INNER JOIN `tbl_fields` AS t2 
					WHERE t2.`element_name` = '{$subsection}'
					{$section}
					AND t1.`field_id` = t2.`id`
					LIMIT 1) 
				UNION
				(SELECT t1.`subsection_id`, t1.field_id
					FROM `tbl_fields_subsectiontabs` AS t1 
					INNER JOIN `tbl_fields` AS t2 
					WHERE t2.`element_name` = '{$subsection}'
					{$section}
					AND t1.`field_id` = t2.`id`
					LIMIT 1) 
				LIMIT 1"
			);
			
			// Get subfield id
			$subfield_id = self::$entryManager->fieldManager->fetchFieldIDFromElementName($field, $id[0]['subsection_id']);

			// Store field data
			$field_id = $id[0]['field_id'];
			if(!is_array(self::$storage['fields'][$context][$field_id][$subfield_id])) {
				self::storeSubsectionFields($context, $field_id, $subfield_id, $mode);
			}

			return $id[0]['subsection_id'];
		}
		
		/**
		 * Store subsection fields
		 *
		 * @param integer $field_id
		 *	The subsection field id
		 * @param integer $subfield_id
		 *	The subsection field subfield id
		 * @param string $mode
		 *	Subfield mode, e. g. 'formatted' or 'unformatted'
		 */
		public static function storeSubsectionFields($context, $field_id, $subfield_id, $mode) {
			if(!empty($context) && !empty($field_id) && !empty($subfield_id)) {
				self::$storage['fields'][$context][$field_id][$subfield_id][] = $mode;
			}
		}

		/**
		 * Preload subsection entries
		 *
		 * @param Array $parents
		 *	Array of entry objects
		 */
		public static function preloadSubsectionEntries($parents) {
			if(empty($parents) || !is_array($parents)) return;
			
			// Get parent data
			$fields = array();
			foreach($parents as $entry) {
				$data = $entry->getData();
				
				// Get relation id
				foreach($data as $field => $settings) {
					if(isset($settings['relation_id'])) {
						if(!is_array($settings['relation_id'])) $settings['relation_id'] = array($settings['relation_id']);
					
						foreach($settings['relation_id'] as $relation_id) {
							if(empty($relation_id)) continue;
							$fields[$field][] = $relation_id;
						}
					}
				}
			}
			
			// Store entries	
			foreach($fields as $field => $relation_id) {
	
				// Check for already loaded entries
				$entry_id = array_diff($relation_id, array_keys(self::$storage['entries']));
				
				// Load new entries
				if(!empty($entry_id)) {
				
					// Get subsection id
					$subsection_id = self::$entryManager->fetchEntrySectionID($entry_id[0]);
				
					// Fetch entries
					$entries = self::$entryManager->fetch($entry_id, $subsection_id);
					
					if(!empty($entries)) {
						foreach($entries as $entry) {
							self::$storage['entries'][$entry->get('id')] = $entry;
						}
					}
				}
			}
		}

		/**
		 * @see http://symphony-cms.com/learn/api/2.2/toolkit/extension/#install
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
					`lock` tinyint(1) DEFAULT '0',
					`recursion_levels` tinyint DEFAULT '0',
					`allow_quantities` tinyint(1) default '0',
			  		PRIMARY KEY  (`id`),
			  		KEY `field_id` (`field_id`)
				)"
			);
			
			// Create Subsection Tabs
			$status[] = Symphony::Database()->query(
				"CREATE TABLE IF NOT EXISTS `tbl_fields_subsectiontabs` (
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
		 * @see http://symphony-cms.com/learn/api/2.2/toolkit/extension/#update
		 */
		public function update($previousVersion) {
			$status = array();
		
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
			
		/*-----------------------------------------------------------------------*/
			
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
			
		/*-----------------------------------------------------------------------*/
			
			if(version_compare($previousVersion, '1.2', '<')) {

				// Add lock column
				$lock = Symphony::Database()->fetchVar('Field', 0, "SHOW COLUMNS FROM `tbl_fields_subsectionmanager` LIKE 'lock'");
				if(!$lock) {
					$status[] = Symphony::Database()->query(
						"ALTER TABLE `tbl_fields_subsectionmanager` ADD `lock` tinyint(1) DEFAULT '0'"
					);
				}	
			}
			
		/*-----------------------------------------------------------------------*/
			
			if(version_compare($previousVersion, '2.0', '<')) {
				
				// Add subsection tabs
				$status[] = Symphony::Database()->query(
					"CREATE TABLE IF NOT EXISTS `sym_fields_subsectiontabs` (
						`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
						`field_id` int(11) unsigned NOT NULL,
						`subsection_id` varchar(255) NOT NULL,
						`static_tabs` varchar(255) DEFAULT NULL,
						`allow_dynamic_tabs` tinyint(1) NOT NULL DEFAULT '1',
					 	PRIMARY KEY (`id`),
				  		KEY `field_id` (`field_id`)
					)"
				);

				// Add recursion levels
				if((boolean)Symphony::Database()->fetchVar('Field', 0, "SHOW COLUMNS FROM `tbl_fields_subsectionmanager` LIKE 'recursion_levels'") == false) {
					$status[] = Symphony::Database()->query(
						"ALTER TABLE `tbl_fields_subsectionmanager` ADD COLUMN `recursion_levels` tinyint DEFAULT '0'"
					);
				}
				
				// Maintenance
				if((boolean)Symphony::Database()->fetchVar('Field', 0, "SHOW COLUMNS FROM `tbl_fields_subsectionmanager` LIKE 'allow_nonunique'") == true) {
					$status[] = Symphony::Database()->query(
						"ALTER TABLE `tbl_fields_subsectionmanager` CHANGE `allow_nonunique` `allow_quantities` tinyint(1) DEFAULT '0'"
					);
				}

				// Add quantities
				if((boolean)Symphony::Database()->fetchVar('Field', 0, "SHOW COLUMNS FROM `tbl_fields_subsectionmanager` LIKE 'allow_quantities'") == false) {
					$status[] = Symphony::Database()->query(
						"ALTER TABLE `tbl_fields_subsectionmanager` ADD COLUMN `allow_quantities` tinyint(1) DEFAULT '0'"
					);
				}

				// Reorder entries to the selected sortorder
				$field_ids = Symphony::Database()->fetchCol('field_id', "
					SELECT
						f.field_id
					FROM
						`tbl_fields_subsectionmanager` AS f
				");
				if(!empty($field_ids) && is_array($field_ids)) {
					$table = 'tbl_'.time();
					Symphony::Database()->query(
						"CREATE TABLE `{$table}` (
							`entry_id` int(11) unsigned NOT NULL,
							`relation_id` int(11) unsigned DEFAULT NULL,
							`sorted` int(11) unsigned DEFAULT 0,
					  		KEY `sorted` (`sorted`)
						) TYPE=MyISAM;"
					);
					foreach($field_ids as $field_id) {
						Symphony::Database()->query(
							"TRUNCATE TABLE `{$table}`"
						);
						Symphony::Database()->query(
							"INSERT INTO `{$table}` (`entry_id`, `relation_id`, `sorted`)
								SELECT `d`.`entry_id`, `d`.`relation_id`, FIND_IN_SET(`d`.`relation_id`, `s`.`order`)
								FROM `tbl_entries_data_{$field_id}` d
								LEFT JOIN `tbl_fields_stage_sorting` s ON `d`.`entry_id` = `s`.`entry_id` AND `s`.`field_id` = {$field_id}
							"
						);
						Symphony::Database()->query(
							"TRUNCATE TABLE `tbl_entries_data_{$field_id}`"
						);
						if((boolean)Symphony::Database()->fetchVar('Field', 0, "SHOW COLUMNS FROM `tbl_entries_data_{$field_id}` LIKE 'quantity'") == false) {
							$status[] = Symphony::Database()->query(
								"ALTER TABLE `tbl_entries_data_{$field_id}` ADD COLUMN `quantity` int(11) unsigned DEFAULT '1'"
							);
						}
						$status[] = Symphony::Database()->query(
							"INSERT INTO `tbl_entries_data_{$field_id}` (`entry_id`, `relation_id`)
								SELECT `t`.`entry_id`, `t`.`relation_id`
								FROM `{$table}` t
								WHERE `t`.`sorted` IS NOT NULL AND `t`.`sorted` > 0
								ORDER BY `t`.`entry_id` ASC, `t`.`sorted` ASC
							"
						);
						// Unsorted entries should be after sorted ones
						$status[] = Symphony::Database()->query(
							"INSERT INTO `tbl_entries_data_{$field_id}` (`entry_id`, `relation_id`)
								SELECT `t`.`entry_id`, `t`.`relation_id`
								FROM `{$table}` t
								WHERE `t`.`sorted` IS NULL OR `t`.`sorted` = 0
								ORDER BY `t`.`entry_id` ASC, `t`.`sorted` ASC
							"
						);
					}
					Symphony::Database()->query(
						"DROP TABLE IF EXISTS `{$table}`"
					);
				}
			}

		/*-----------------------------------------------------------------------*/
			
			// Report status
			if(in_array(false, $status, true)) {
				return false;
			}
			else {
				return true;
			}
		}

		/**
		 * @see http://symphony-cms.com/learn/api/2.2/toolkit/extension/#uninstall
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
