<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2010 Nico de Haen
*  All rights reserved
*
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

/**
 * Load settings from yaml file and from TYPO3_CONF_VARS extConf
 *
 * @package Extbase
 * @subpackage Utility
 */
class Tx_ExtensionBuilder_Utility_ConfigurationManager {

	public static $settingsDir = 'Configuration/ExtensionBuilder/';
	public static $oldSettingsDir = 'Configuration/Kickstarter/';
	
	/**
	 * 
	 * @param array $typoscript
	 */
	public static function getSettings($typoscript){
		$settings = $typoscript['module.']['extension_builder.']['settings.'];
		if(empty($settings['codeTemplateRootPath'])){
			$settings['codeTemplateRootPath'] = 'EXT:extension_builder/Resources/Private/CodeTemplates/Extbase/';
		}
		$settings['codeTemplateRootPath'] = self::substituteExtensionPath($settings['codeTemplateRootPath']);
		$settings['extConf'] = Tx_ExtensionBuilder_Utility_ConfigurationManager::getExtensionBuilderSettings();
		return $settings;
	}
	
	/**
	 * Get the extension_builder configuration (ext_template_conf)
	 */
	public static function getExtensionBuilderSettings(){
		$settings = array();
		if(!empty($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['extension_builder'])){
			$settings = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['extension_builder']);
		}
		return $settings;
	}

	/**
	 *
	 * @param string $extensionKey
	 * @return array settings
	 */
	public static function getExtensionSettings($extensionKey){
		$settings = array();
		$settingsFile = self::getSettingsFile($extensionKey);
		if (file_exists($settingsFile)) {
			$yamlParser = new Tx_ExtensionBuilder_Utility_SpycYAMLParser();
			$settings = $yamlParser->YAMLLoadString(file_get_contents($settingsFile));
		}
		else t3lib_div::devlog('No settings found: '.$settingsFile,'extension_builder',2);

		return $settings;
	}

	/**
	 * get the file name and path of the settings file
	 * @param string $extensionKey
	 * @return string path
	 */
	public static function getSettingsFile($extensionKey){
		$extensionDir = PATH_typo3conf.'ext/'.$extensionKey.'/';
		$settingsFile =  $extensionDir.self::$settingsDir . 'settings.yaml';
		if(!file_exists($settingsFile) && file_exists($extensionDir.self::$oldSettingsDir . 'settings.yaml')){
			// upgrade from an extension that was built with the extbase_kickstarter
			mkdir($extensionDir.self::$settingsDir);
			copy($extensionDir.self::$oldSettingsDir . 'settings.yaml', $extensionDir.self::$settingsDir . 'settings.yaml');
			$settingsFile =  $extensionDir.self::$settingsDir . 'settings.yaml';
		}
		return $settingsFile;
	}

	/**
	 * 
	 * @param Tx_ExtensionBuilder_Domain_Model_Extension $extension
	 * @param string $codeTemplateRootPath
	 */
	static public function createInitialSettingsFile($extension,$codeTemplateRootPath){
		t3lib_div::mkdir_deep($extension->getExtensionDir(),self::$settingsDir);
		$settings = file_get_contents($codeTemplateRootPath.'Configuration/ExtensionBuilder/settings.yamlt');
		$settings = str_replace('{extension.extensionKey}',$extension->getExtensionKey(),$settings);
		$settings = str_replace('<f:format.date>now</f:format.date>',date('Y-m-d H:i'),$settings);
		t3lib_div::writeFile($extension->getExtensionDir().self::$settingsDir.'settings.yaml', $settings);
	}
	
	/**
	 * Replace the EXT:extkey prefix with the appropriate path
	 * @param string $encodedTemplateRootPath
	 */
	static public function substituteExtensionPath($encodedTemplateRootPath){
		if(t3lib_div::isFirstPartOfStr($encodedTemplateRootPath,'EXT:')){
			list($extKey, $script) = explode('/', substr($encodedTemplateRootPath, 4), 2);
			if ($extKey && t3lib_extMgm::isLoaded($extKey)) {
				return t3lib_extMgm::extPath($extKey) . $script;
				
			}
		} else if(t3lib_div::isAbsPath($encodedTemplateRootPath)){
			return $encodedTemplateRootPath;
		} else {
			return PATH_site.$encodedTemplateRootPath;
		}
	}

	static public function fixExtensionBuilderJSON($extensionConfigurationJSON){
		$extensionConfigurationJSON['modules'] = self::generateUniqueIDs($extensionConfigurationJSON['modules']);
		$extensionConfigurationJSON['modules'] = self::mapAdvancedMode($extensionConfigurationJSON['modules']);
		$extensionConfigurationJSON['modules'] = self::resetOutboundedPositions($extensionConfigurationJSON['modules']);
		$extensionConfigurationJSON = self::reArrangeRelations($extensionConfigurationJSON);
		return $extensionConfigurationJSON;
	}

	/**
	 * enable unique IDs to track modifications of models, properties and relations
	 * this method sets unique IDs to the JSON array, if it was created
	 * with an older version of the extension builder
	 *
	 * @param $jsonConfig
	 * @return array $jsonConfig with unique IDs
	 */
	static public function generateUniqueIDs($jsonConfig){
		//  generate unique IDs
		foreach($jsonConfig as &$module){

			if(empty($module['value']['objectsettings']['uid'])){
				$module['value']['objectsettings']['uid'] = md5(microtime().$module['propertyName']);
			}

			for($i=0;$i < count($module['value']['propertyGroup']['properties']);$i++){
				// don't save empty properties
				if(empty($module['value']['propertyGroup']['properties'][$i]['propertyName'])){
					unset($module['value']['propertyGroup']['properties'][$i]);
				}
				else if(empty($module['value']['propertyGroup']['properties'][$i]['uid'])){
					$module['value']['propertyGroup']['properties'][$i]['uid'] = md5(microtime().$module['value']['propertyGroup']['properties'][$i]['propertyName']);
				}
			}
			for($i=0;$i < count($module['value']['relationGroup']['relations']);$i++){
				// don't save empty relations
				if(empty($module['value']['relationGroup']['relations'][$i]['relationName'])){
					unset($module['value']['relationGroup']['relations'][$i]);
					t3lib_div::devlog('Unset called:'.$i,'extbase',0,$jsonConfig);
				}
				else if(empty($module['value']['relationGroup']['relations'][$i]['uid'])){
					$module['value']['relationGroup']['relations'][$i]['uid'] = md5(microtime().$module['value']['relationGroup']['relations'][$i]['relationName']);
				}
			}
		}
		return $jsonConfig;
	}


	/**
	 * copy values from advanced fieldset to simple mode fieldset and vice versa
	 *
	 * enables compatibility with JSON from older versions of the extension builder
	 *
	 * @param array $jsonConfig
	 */
	static public function mapAdvancedMode($jsonConfig){
		foreach($jsonConfig as &$module){
			for($i=0;$i < count($module['value']['relationGroup']['relations']);$i++){
				if(empty($module['value']['relationGroup']['relations'][$i]['advancedSettings'])){
					$module['value']['relationGroup']['relations'][$i]['advancedSettings'] = array();
					$module['value']['relationGroup']['relations'][$i]['advancedSettings']['relationType'] = $module['value']['relationGroup']['relations'][$i]['relationType'];
					$module['value']['relationGroup']['relations'][$i]['advancedSettings']['propertyIsExcludeField'] = $module['value']['relationGroup']['relations'][$i]['propertyIsExcludeField'];
				}
				else {
					foreach($module['value']['relationGroup']['relations'][$i]['advancedSettings'] as $key => $value){
						$module['value']['relationGroup']['relations'][$i][$key] = $value;
					}

				}
			}
		}
		return $jsonConfig;
	}

	/**
	 * just a temporary workaround until the new UI is available
	 *
	 * @param array $jsonConfig
	 */
	static public function resetOutboundedPositions($jsonConfig){
		foreach($jsonConfig as &$module){
			if($module['config']['position'][0] < 0){
				$module['config']['position'][0] = 10;
			}
			if($module['config']['position'][1] < 0){
				$module['config']['position'][1] = 10;
			}
		}
		return $jsonConfig;
	}

	/**
	 * This is a workaround for the bad design in WireIt
	 * All wire terminals are only identified by a simple index,
	 * that does not reflect deleting of models and relations
	 *
	 * @param array $jsonConfig
	 */
	static public function reArrangeRelations($jsonConfig){
		foreach($jsonConfig['wires'] as &$wire){
			$parts = explode('_',$wire['src']['terminal']); // format: relation_1
			$supposedRelationIndex = $parts[1];
			$supposedModuleIndex = $wire['src']['moduleId'];
			$uid = $wire['src']['uid'];
			$wire['src'] = self::findModuleIndexByRelationUid($wire['src']['uid'],$jsonConfig['modules'],$wire['src']['moduleId'],$supposedRelationIndex);
			$wire['src']['uid'] = $uid;

			$supposedModuleIndex = $wire['tgt']['moduleId'];
			$uid = $wire['tgt']['uid'];
			$wire['tgt'] = self::findModuleIndexByRelationUid($wire['tgt']['uid'],$jsonConfig['modules'],$wire['tgt']['moduleId']);
			$wire['tgt']['uid'] = $uid;
		}
		return $jsonConfig;
	}

	/**
	 *
	 * @param int $uid
	 * @param array $modules
	 * @param int $supposedModuleIndex
	 * @param int $supposedRelationIndex
	 */
	static public function findModuleIndexByRelationUid($uid,$modules,$supposedModuleIndex,$supposedRelationIndex = NULL){
		$result = array(
				'moduleId' => $supposedModuleIndex
			);
		if($supposedRelationIndex == NULL){
			$result['terminal'] = 'SOURCES';
			if($modules[$supposedModuleIndex]['value']['objectsettings']['uid'] == $uid){
				return $result; // everything as expected
			}
			else {
				$moduleCounter = 0;
				foreach($modules as $module){
					if($module['value']['objectsettings']['uid'] == $uid){
						$result['moduleId'] = $moduleCounter;
						return $result;
					}
				}
			}
		}
		else if($modules[$supposedModuleIndex]['value']['relationGroup']['relations'][$supposedRelationIndex]['uid'] == $uid){
			$result['terminal'] = 'relationWire_' . $supposedRelationIndex;
			return $result; // everything as expected
		}
		else {
			$moduleCounter = 0;
			foreach($modules as $module){
				$relationCounter = 0;
				foreach($module['value']['relationGroup']['relations'] as $relation){
					if($relation['uid'] == $uid){
						$result['moduleId'] = $moduleCounter;
						$result['terminal'] = 'relationWire_' . $relationCounter;
						return $result;
					}
					$relationCounter++;
				}
				$moduleCounter++;
			}
		}
	}

}

?>