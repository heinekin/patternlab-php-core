<?php

/*!
 * Installer Util Class
 *
 * Copyright (c) 2014 Dave Olsen, http://dmolsen.com
 * Licensed under the MIT license
 *
 * Various functions to be run before and during composer package installs
 *
 */

namespace PatternLab;

use \PatternLab\Config;
use \PatternLab\Console;
use \PatternLab\Timer;
use \Symfony\Component\Filesystem\Filesystem;
use \Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use \Symfony\Component\Finder\Finder;

class InstallerUtil {
	
	protected static $fs;
	
	/**
	 * Move the component files from the package to their location in the patternlab-components dir
	 * @param  {String/Array}   the items to create a fileList for
	 *
	 * @return {Array}          list of files destination and source
	 */
	protected static function buildFileList($initialList) {
		
		$fileList = array();
		
		// see if it's an array. loop over the multiple items if it is
		if (is_array($initialList)) {
			foreach ($initialList as $listItem) {
				$fileList[$listItem] = $listItem;
			}
		} else {
			$fileList[$listItem] = $listItem;
		}
		
		return $fileList;
		
	}
	
	/**
	* Common init sequence
	*/
	protected static function init() {
		
		// initialize the console to print out any issues
		Console::init();
		
		// initialize the config for the pluginDir
		$baseDir = __DIR__."/../../../../../";
		Config::init($baseDir,false);
		
		// load the file system function
		self::$fs = new Filesystem();
	}
	
	/**
	 * Parse the component types to figure out what needs to be moved and added to the component JSON files
	 * @param  {String}    file path to move
	 * @param  {String}    file path to move to
	 * @param  {String}    the name of the package
	 * @param  {String}    the base directory for the source of the files
	 * @param  {String}    the base directory for the destination of the files (publicDir or sourceDir)
	 * @param  {Array}     the list of files to be moved
	 */
	protected static function moveFiles($source,$destination,$packageName,$sourceBase,$destinationBase) {
		
		// make sure the destination base exists
		if (!is_dir($destinationBase)) {
			mkdir($destinationBase);
		}
		
		// clean any * or / on the end of $destination
		$destination = (($destination != "*") && ($destination[strlen($destination)-1] == "*")) ? substr($destination,0,-1) : $destination;
		$destination = ($destination[strlen($destination)-1] == "/") ? substr($destination,0,-1) : $destination;
		
		// decide how to move the files. the rules:
		// src        ~ dest        -> action
		// *          ~ *           -> mirror all in {srcroot}/ to {destroot}/
		// *          ~ path/*      -> mirror all in {srcroot}/ to {destroot}/path/
		// foo/*      ~ path/*      -> mirror all in {srcroot}/foo/ to {destroot}/path/
		// foo/s.html ~ path/k.html -> mirror {srcroot}/foo/s.html to {destroot}/path/k.html
		
		if (($source == "*") && ($destination == "*")) {
			if (!self::pathExists($packageName,$destinationBase."/")) {
				self::$fs->mirror($sourceBase,$destinationBase."/");
			}
		} else if ($source == "*") {
			if (!self::pathExists($packageName,$destinationBase."/".$destination)) {
				self::$fs->mirror($sourceBase,$destinationBase."/".$destination);
			}
		} else if ($source[strlen($source)-1] == "*") {
			$source = rtrim($source,"/*");
			if (!self::pathExists($packageName,$destinationBase."/".$destination)) {
				self::$fs->mirror($sourceBase.$source,$destinationBase."/".$destination);
			}
		} else {
			$pathInfo       = explode("/",$destination);
			$file           = array_pop($pathInfo);
			$destinationDir = implode("/",$pathInfo);
			if (!self::$fs->exists($destinationBase."/".$destinationDir)) {
				self::$fs->mkdir($destinationBase."/".$destinationDir);
			}
			if (!self::pathExists($packageName,$destinationBase."/".$destination)) {
				self::$fs->copy($sourceBase.$source,$destinationBase."/".$destination,true);
			}
		}
		
	}
	
	/**
	 * Parse the component types to figure out what needs to be added to the component JSON files
	 * @param  {String}    the name of the package
	 * @param  {String}    the base directory for the source of the files
	 * @param  {String}    the base directory for the destination of the files (publicDir or sourceDir)
	 * @param  {Array}     the list of files to be parsed for component types
	 * @param  {String}    template extension for templates
	 * @param  {String}    the javascript to run on ready
	 * @param  {String}    the javascript to run as a callback
	 */
	protected static function parseComponentList($packageName,$sourceBase,$destinationBase,$componentFileList,$templateExtension,$onready,$callback) {
		
		/*
		iterate over a source or source dirs and copy files into the componentdir. 
		use file extensions to add them to the appropriate type arrays below. so...
			"patternlab": {
				"dist": {
					"componentDir": {
						{ "*": "*" }
					}
				}
				"onready": ""
				"callback": ""
				"templateExtension": ""
			}
		}
		
		*/
		
		// decide how to type list files. the rules:
		// src        ~ dest        -> action
		// *          ~ *           -> iterate over all files in {srcroot}/ and create a type listing
		// foo/*      ~ path/*      -> iterate over all files in {srcroot}/foo/ and create a type listing
		// foo/s.html ~ path/k.html -> create a type listing for {srcroot}/foo/s.html
		
		// set-up component types store
		$componentTypes = array("stylesheets" => array(), "javascripts" => array(), "templates" => array());
		
		// iterate over the file list
		foreach ($componentFileList as $componentItem) {
			
			// retrieve the source & destination
			$source      = self::removeDots(key($componentItem));
			$destination = self::removeDots($componentItem[$source]);
			
			if (($source == "*") || ($source[strlen($source)-1] == "*")) {
				
				// build the source & destination
				$source      = (strlen($source) > 2)      ? rtrim($source,"/*") : "";
				$destination = (strlen($destination) > 2) ? rtrim($destination,"/*") : "";
				
				// get files
				$finder = new Finder();
				$finder->files()->in($sourceBase.$source);
				
				// iterate over the returned objects
				foreach ($finder as $file) {
					
					$ext = $file->getExtension();
					
					if ($ext == "css") {
						$componentTypes["stylesheets"][] = str_replace($sourceBase.$source,$destination,$file->getPathname());
					} else if ($ext == "js") {
						$componentTypes["javascripts"][] = str_replace($sourceBase.$source,$destination,$file->getPathname());
					} else if ($ext == $templateExtension) {
						$componentTypes["templates"][]   = str_replace($sourceBase.$source,$destination,$file->getPathname());
					}
				
				}
				
			} else {
				
				$bits = explode(".",$source);
				
				if (count($bits) > 0) {
					
					$ext = $bits[count($bits)-1];
					
					if ($ext == "css") {
						$componentTypes["stylesheets"][] = $destination;
					} else if ($ext == "js") {
						$componentTypes["javascripts"][] = $destination;
					} else if ($ext == $templateExtension) {
						$componentTypes["templates"][]   = $destination;
					}
					
				}
				
			}
			
		}
		
		/*
		FOR USE AS A PACKAGE TO BE LOADED LATER
		{
			"name": "pattern-lab-plugin-kss",
			"templates": { "filename": "filepath" }, // replace slash w/ dash in filename. replace extension
			"stylesheets": [ ],
			"javascripts": [ ],
			"onready": "",
			"callback": ""
		}
		*/
		$packageInfo                = array();
		$packageInfo["name"]        = $packageName;
		$packageInfo["templates"]   = array();
		foreach ($componentTypes["templates"] as $templatePath) {
			$templateKey = preg_replace("/\W/","-",str_replace(".".$templateExtension,"",$templatePath));
			$packageInfo["templates"][$templateKey] = $templatePath;
		}
		$packageInfo["stylesheets"] = $componentTypes["stylesheets"];
		$packageInfo["javascripts"] = $componentTypes["javascripts"];
		$packageInfo["onready"]     = $onready;
		$packageInfo["callback"]    = $callback;
		$packageInfoPath            = Config::getOption("componentDir")."/packages/".str_replace("/","-",$packageName).".json";
		
		// double-check the dirs are created
		if (!is_dir(Config::getOption("componentDir"))) {
			mkdir(Config::getOption("componentDir"));
		}
		
		if (!is_dir(Config::getOption("componentDir")."/packages/")) {
			mkdir(Config::getOption("componentDir")."/packages/");
		}
		
		// write out the package info
		file_put_contents($packageInfoPath,json_encode($packageInfo));
		
	}
	
	/**
	 * Move the files from the package to their location in the public dir or source dir
	 * @param  {String}    the name of the package
	 * @param  {String}    the base directory for the source of the files
	 * @param  {String}    the base directory for the destintation of the files (publicDir or sourceDir)
	 * @param  {Array}     the list of files to be moved
	 */
	protected static function parseFileList($packageName,$sourceBase,$destinationBase,$fileList) {
		
		foreach ($fileList as $fileItem) {
			
			// retrieve the source & destination
			$source      = self::removeDots(key($fileItem));
			$destination = self::removeDots($fileItem[$source]);
			
			// depending on the source handle things differently. mirror if it ends in /*
			self::moveFiles($source,$destination,$packageName,$sourceBase,$destinationBase);
			
		}
		
	}
	
	/**
	 * Check to see if the path already exists. If it does prompt the user to double-check it should be overwritten
	 * @param  {String}    the package name
	 * @param  {String}    path to be checked
	 *
	 * @return {Boolean}   if the path exists and should be overwritten
	 */
	protected static function pathExists($packageName,$path) {
		
		if (self::$fs->exists($path)) {
			
			// set-up a human readable prompt
			$humanReadablePath = str_replace(Config::getOption("baseDir"), "./", $path);
			
			// set if the prompt should fire
			$prompt = true;
			
			// are we checking a directory?
			if (is_dir($path)) {
				
				// see if the directory is essentially empty
				$files = scandir($path);
				foreach ($files as $key => $file) {
					$ignore = array("..",".",".gitkeep","README",".DS_Store");
					$file = explode("/",$file);
					if (in_array($file[count($file)-1],$ignore)) {
						unset($files[$key]);
					}
				}
				
				if (empty($files)) {
					$prompt = false;
				}
				
			}
			
			if ($prompt) {
				
				// prompt for input using the supplied query
				$prompt  = "the path <path>".$humanReadablePath."</path> already exists. overwrite it with the contents from the <path>".$packageName."</path> package?";
				$options = "Y/n";
				$input   = Console::promptInput($prompt,$options);
				
				if ($input == "y") {
					Console::writeTag("ok","contents of <path>".$humanReadablePath."</path> being overwritten...", false, true);
					return false;
				} else {
					Console::writeWarning("contents of <path>".$humanReadablePath."</path> weren't overwritten. some parts of the <path>".$packageName."</path> package may be missing...", false, true);
					return true;
				}
				
			}
			
			return false;
			
		}
		
		return false;
		
	}
	
	/**
	 * Run the PL tasks when a package is installed
	 * @param  {Object}     a script event object from composer
	 */
	public static function postPackageInstall($event) {
		
		// run the console and config inits
		self::init();
		
		// run the tasks based on what's in the extra dir
		self::runTasks($event,"install");
		
	}
	
	/**
	 * Run the PL tasks when a package is updated
	 * @param  {Object}     a script event object from composer
	 */
	public static function postPackageUpdate($event) {
		
		// run the console and config inits
		self::init();
		
		self::runTasks($event,"update");
		
	}
	
	/**
	 * Make sure certain things are set-up before running composer's install
	 * @param  {Object}     a script event object from composer
	 */
	public static function preInstallCmd($event) {
		
		// run the console and config inits
		self::init();
		
		// default vars
		$sourceDir   = Config::getOption("sourceDir");
		$packagesDir = Config::getOption("packagesDir");
		
		// check directories
		if (!is_dir($sourceDir)) {
			mkdir($sourceDir);
		}
		
		if (!is_dir($packagesDir)) {
			mkdir($packagesDir);
		}
		
	}
	
	/**
	 * Make sure pattern engines and listeners are removed on uninstall
	 * @param  {Object}     a script event object from composer
	 */
	public static function prePackageUninstallCmd($event) {
		
		// run the console and config inits
		self::init();
		
		// get package info
		$package   = $event->getOperation()->getPackage();
		$type      = $package->getType();
		$name      = $package->getName();
		$pathBase  = Config::getOption("packagesDir")."/".$name;
		
		// see if the package has a listener and remove it
		self::scanForListener($pathBase,true);
		
		// see if the package is a pattern engine and remove the rule
		if ($type == "patternlab-patternengine") {
			self::scanForPatternEngineRule($pathBase,true);
		}
		
		// go over .json in patternlab-components/, remove references to packagename
		
	}
	
	/**
	 * Remove dots from the path to make sure there is no file system traversal when looking for or writing files
	 * @param  {String}    the path to check and remove dots
	 *
	 * @return {String}    the path minus dots
	 */
	protected static function removeDots($path) {
		$parts = array();
		foreach (explode("/", $path) as $chunk) {
			if ((".." !== $chunk) && ("." !== $chunk) && ("" !== $chunk)) {
				$parts[] = $chunk;
			}
		}
		return implode("/", $parts);
	}
	
	/**
	 * Handle some Pattern Lab specific tasks based on what's found in the package's composer.json file
	 * @param  {Object}     a script event object from composer
	 * @param  {String}     the type of event starting the runTasks command
	 */
	protected static function runTasks($event,$type) {
		
		// get package info
		$package   = ($type == "install") ? $event->getOperation()->getPackage() : $event->getOperation()->getTargetPackage();
		$extra     = $package->getExtra();
		$type      = $package->getType();
		$name      = $package->getName();
		$pathBase  = Config::getOption("packagesDir")."/".$name;
		$pathDist  = $pathBase."/dist/";
		
		// make sure we're only evaluating pattern lab packages
		if (strpos($type,"patternlab-") !== false) {
			
			// make sure that it has the name-spaced section of data to be parsed
			if (isset($extra["patternlab"])) {
				
				// rebase $extra
				$extra = $extra["patternlab"];
				
				// move assets to the base directory
				if (isset($extra["dist"]["baseDir"])) {
					self::parseFileList($name,$pathDist,Config::getOption("baseDir"),$extra["dist"]["baseDir"]);
				}
				
				// move assets to the public directory
				if (isset($extra["dist"]["publicDir"])) {
					self::parseFileList($name,$pathDist,Config::getOption("publicDir"),$extra["dist"]["publicDir"]);
				}
				
				// move assets to the source directory
				if (isset($extra["dist"]["sourceDir"])) {
					self::parseFileList($name,$pathDist,Config::getOption("sourceDir"),$extra["dist"]["sourceDir"]);
				}
				
				// move assets to the scripts directory
				if (isset($extra["dist"]["scriptsDir"])) {
					self::parseFileList($name,$pathDist,Config::getOption("scriptsDir"),$extra["dist"]["scriptsDir"]);
				}
				
				// move assets to the data directory
				if (isset($extra["dist"]["dataDir"])) {
					self::parseFileList($name,$pathDist,Config::getOption("dataDir"),$extra["dist"]["dataDir"]);
				}
				
				// move assets to the components directory
				if (isset($extra["dist"]["componentDir"])) {
					$templateExtension = isset($extra["templateExtension"]) ? $extra["templateExtension"] : "mustache";
					$onready           = isset($extra["onready"]) ? $extra["onready"] : "";
					$callback          = isset($extra["callback"]) ? $extra["callback"] : "";
					$componentDir      = Config::getOption("componentDir");
					self::parseComponentList($name,$pathDist,$componentDir."/".$name,$extra["dist"]["componentDir"],$templateExtension,$onready,$callback);
					self::parseFileList($name,$pathDist,$componentDir."/".$name,$extra["dist"]["componentDir"]);
				}
				
				// see if we need to modify the config
				if (isset($extra["config"])) {
					
					foreach ($extra["config"] as $optionInfo) {
						
						// get config info
						$option = key($optionInfo);
						$value  = $optionInfo[$option];
						
						// update the config option
						Config::updateConfigOption($option,$value);
						
					}
					
				}
				
			}
			
			// see if the package has a listener
			self::scanForListener($pathBase);
			
			// see if the package is a pattern engine
			if ($type == "patternlab-patternengine") {
				self::scanForPatternEngineRule($pathBase);
			}
			
		}
		
	}
	
	/**
	 * Scan the package for a listener
	 * @param  {String}     the path for the package
	 */
	protected static function scanForListener($pathPackage,$remove = false) {
		
		// get listener list path
		$pathList = Config::getOption("packagesDir")."/listeners.json";
		
		// make sure listeners.json exists. if not create it
		if (!file_exists($pathList)) {
			file_put_contents($pathList, "{ \"listeners\": [ ] }");
		}
		
		// load listener list
		$listenerList = json_decode(file_get_contents($pathList),true);
		
		// set-up a finder to find the listener
		$finder = new Finder();
		$finder->files()->name('PatternLabListener.php')->in($pathPackage);
		
		// iterate over the returned objects
		foreach ($finder as $file) {
			
			// create the name
			$dirs         = explode("/",$file->getPath());
			$listenerName = "\\".$dirs[count($dirs)-2]."\\".$dirs[count($dirs)-1]."\\".str_replace(".php","",$file->getFilename());
			
			// check to see what we should do with the listener info
			if (!$remove && !in_array($listenerName,$listenerList["listeners"])) {
				$listenerList["listeners"][] = $listenerName;
			} else if ($remove && in_array($listenerName,$listenerList["listeners"])) {
				$key = array_search($listenerName, $listenerList["listeners"]);
				unset($listenerList["listeners"][$key]);
			}
			
			// write out the listener list
			file_put_contents($pathList,json_encode($listenerList));
			
		}
		
	}
	
	/**
	 * Scan the package for a pattern engine rule
	 * @param  {String}     the path for the package
	 */
	protected static function scanForPatternEngineRule($pathPackage,$remove = false) {
		
		// get listener list path
		$pathList = Config::getOption("packagesDir")."/patternengines.json";
		
		// make sure patternengines.json exists. if not create it
		if (!file_exists($pathList)) {
			file_put_contents($pathList, "{ \"patternengines\": [ ] }");
		}
		
		// load pattern engine list
		$patternEngineList = json_decode(file_get_contents($pathList),true);
		
		// set-up a finder to find the pattern engine
		$finder = new Finder();
		$finder->files()->name("PatternEngineRule.php")->in($pathPackage);
		
		// iterate over the returned objects
		foreach ($finder as $file) {
			
			/// create the name
			$dirs              = explode("/",$file->getPath());
			$patternEngineName = "\\".$dirs[count($dirs)-3]."\\".$dirs[count($dirs)-2]."\\".$dirs[count($dirs)-1]."\\".str_replace(".php","",$file->getFilename());
			
			// check what we should do with the pattern engine info
			if (!$remove && !in_array($patternEngineName, $patternEngineList["patternengines"])) {
				$patternEngineList["patternengines"][] = $patternEngineName;
			} else if ($remove && in_array($patternEngineName, $patternEngineList["patternengines"])) {
				$key = array_search($patternEngineName, $patternEngineList["patternengines"]);
				unset($patternEngineList["patternengines"][$key]);
			}
			
			// write out the pattern engine list
			file_put_contents($pathList,json_encode($patternEngineList));
			
		}
		
	}
	
}
