<?php

/**
 * A generic exception used from within this set of behat classes
 */
class Behat_Exception extends Exception {}

/**
 * A class to encapsulate behat configuration (basically an array of options)
 */
class Behat_Config 
{

	/**
	 * @var array
	 */
	private $options = array();

	/**
	 * @param array $options
	 */
	public function __construct($options = array()) 
	{
		$this->options = $options;
	}

	/**
	 * Get a configuration option by key
	 *
	 * @param string $key
	 *
	 * @return mixed
	 */
	public function getOption($key) 
	{
		return isset($this->options[$key]) ? $this->options[$key] : null;
	}

}

/**
 * An interface to work with behat features
 */
interface Behat_Feature 
{

	/**
	 * Return the feature's name
	 *
	 * @return string
	 */
	public function getName();

	/**
	 * Return the feature's contents
	 *
	 * @return string
	 */
	public function getTestScenarios();
}

/**
 * A reference to a standard behat feature that exists as a file on disk
 */
class Behat_Feature_File 
	implements Behat_Feature 
{

	/**
	 * @var string
	 */
	private $fileReference = null;

	/**
	 * @param string $fileReference
	 */
	public function __construct($fileReference)
	{
		
		if (is_file($fileReference)) {
			$this->fileReference = $fileReference;
		} else {
			throw new Behat_Exception('The reference [' . $fileReference . '] is not a valid file.');
		}

	}

	/**
	 * Return the feature's name
	 *
	 * @return string
	 */
	public function getName() 
	{
		return pathinfo($this->fileReference, PATHINFO_FILENAME);
	}

 	/**
	 * Return the feature's file's file path
	 *
	 * @return string
	 */
	public function getFileReference()
	{
		return $this->fileReference;
	}

	/**
	 * Return the feature's contents
	 *
	 * @return string
	 */
	public function getTestScenarios() 
	{
		return file_get_contents($this->fileReference);
	}

}

/**
 * A behat feature, composed by aggregation, which allows users to apply values to named parameters
 */
class Behat_Feature_Template 
	implements Behat_Feature 
{

	/**
	 * @var Behat_Feature_File
	 */
	private $featureFile = null;

	/**
	 * @var string
	 */
	private $testScenarios = '';

	/**
	 * @param Behat_Feature_File $featureFile
	 */
	public function __construct(Behat_Feature_File $featureFile) 
	{
		$this->featureFile = $featureFile;
		$this->testScenarios = $featureFile->getTestScenarios();
	}

	/**
	 * Apply a value to a template variable's placeholder
	 * 
	 * @param string $name
	 * @param string $value
	 *
	 * @return void
	 */
	public function apply($name, $value) 
	{
		$this->testScenarios = str_replace('<' . $name . '>', (string) $value, $this->testScenarios);
	}

	/**
	 * Return the feature's name
	 *
	 * @return string
	 */
	public function getName() 
	{
		return $this->featureFile->getName();
	}

	/**
	 * Return the feature's contents
	 *
	 * @return string
	 */
	public function getTestScenarios() 
	{
		return $this->testScenarios;
	}

}

/**
 * An interface to execute behat for a particular feature file
 */
interface Behat_Processor 
{

	/**
	 * Execute behat on a specific feature file
	 *
	 * @param Behat_Feature_File $featureFile
	 * @return array
	 */
	public function execute(Behat_Feature_File $featureFile);
}

/**
 * A processor that executes behat using php's exec() function
 */
class Behat_Processor_Cli 
	implements Behat_Processor 
{

	/**
	 * @var Behat_Config
	 */
	private $config = null;

	/**
	 * @var string
	 */
	private $command = null;

	/**
	 * @param Behat_Config $config
	 * @param string $command The Behat CLI command
	 */
	public function __construct(Behat_Config $config, $command = 'behat') 
	{
		$this->config = $config;
		$this->command = $command;
	}

	/**
	 * Execute Behat on a specific feature file
	 *
	 * @param Behat_Feature_File $featureFile
	 * @return array
	 */
	public function execute(Behat_Feature_File $featureFile) 
	{

		//--------------------------------------------------
		// prepare Behat command

		$command = $this->command;

		// add the config parameter
		$behatConfigFile = $this->config->getOption('config');
		if (!empty($behatConfigFile)) {
			$command .= ' --config=' . $behatConfigFile;
		}

		// append the specific feature file
		$command .= ' ' . $featureFile->getFileReference();
		
		//--------------------------------------------------
		// execute Behat on the command-line

		// echo $command;
		
		$output = array();
		exec($command, $output, $returnCode);

		if (empty($output)) {
			throw new Behat_Exception('Expecting output from command [' . $command . '].');
		}

		//--------------------------------------------------
		// parse output for data

		$lastIndex = count($output)-1;

		$elapsedTime = $output[$lastIndex];
		$result = $output[$lastIndex-1];

		$matches = array();
		preg_match('/.*(\d).*steps.*(\d).*passed/Um', $result, $matches);

		$steps = isset($matches[1]) ? $matches[1] : null;
		$passed = isset($matches[2]) ? $matches[2] : null;
		$failed = !is_null($steps) ? ($steps - $passed) : null;

		return array(
			'command' => $command,
			'success' => ($failed == 0),
			'elapsed' => $elapsedTime,
			'steps' => $steps,
			'passed' => $passed,
			'failed' => $failed,
			'output' => implode("\n", $output)
		);

	}

}


/**
 * Single class to test a feature file with dynamic parameters
 */
class Behat_Service_TestFeatureFileWithParameters 
{

	/**
	 * @var Behat_Processor
	 */
	private $processor = null;

	/**
	 * @var Behat_Config
	 */
	private $config = null;

	/**
	 * @var array
	 */
	private $lastResult = null;

	/**
	 * @param Behat_Processor $processor
	 * @param Behat_Config $config
	 */
	public function __construct(Behat_Processor $processor, Behat_Config $config) 
	{
		$this->processor = $processor;
		$this->config = $config;
	}

	/**
	 * @param Behat_Feature_Template $featureTemplate The feature file template to execute
	 * @param array $parameters The 'user input' or dynamic parameters to test with.
	 * 
	 * @return void
	 */
	public function execute(Behat_Feature_Template $featureTemplate, array $parameters) 
	{

		// apply parameters to feature file
		foreach($parameters as $variableName => $variableValue) {
			$featureTemplate->apply($variableName, $variableValue);
		}

		// create temporary feature file
		$temporaryFeatureFile = $this->createTemporaryFeatureFile($featureTemplate->getName());
		$featureFile = $this->copyFeatureAs($featureTemplate, $temporaryFeatureFile);		

		$this->lastResult = $this->processor->execute($featureFile);

		// clean up; do not leave any temporary files
		//unlink($featureFile->getFileReference());

	}

	/**
	 * Create a temporary feature file using a random file name
	 * 
	 * @param string $prefix The prefix used in php's tempnam() function
	 */
	private function createTemporaryFeatureFile($prefix)
	{
		$workspacePath = $this->config->getOption('workspace');
		if (empty($workspacePath) || !is_dir($workspacePath)) {
			throw new Behat_Exception('The behat configuration must define a read/writable workspace path using the key named "workspace".');
		}

		$temporaryFile = tempnam($workspacePath, $prefix . '-');

		// rename temporary file with a '.feature' suffix
		$temporaryFeatureFile = $temporaryFile . '.feature';
		rename($temporaryFile, $temporaryFeatureFile);

		return $temporaryFeatureFile;
	}

	/**
	 * Copy feature file's test scenarios into another file
	 * 
	 * @param Behat_Feature $featureFile
	 * @param string $fileReferenceToCopy
	 *
	 * @return Behat_Feature_File
	 */
	private function copyFeatureAs(Behat_Feature $featureFile, $fileReferenceToCopy)
	{
		chmod($fileReferenceToCopy, 0755);
		file_put_contents($fileReferenceToCopy, $featureFile->getTestScenarios());

		return new Behat_Feature_File($fileReferenceToCopy);
	}

	/**
	 * Return the last result from this class's execution
	 * 
	 * @return array
	 */
	public function getLastResult() {
		return $this->lastResult;
	}

}

/**
 * Single class to list available feature files templates in a directory
 */
class Behat_Service_ListAvailableFeatureTemplates 
{

	/**
	 * @param string $targetPath
	 *
	 * @return array
	 */
	public function execute($targetPath) {

		$files = scandir($targetPath);

		$features = array();
		foreach($files as $file) {

			// for any file that matches a feature file
			if (preg_match('/\.feature$/', $file)) {
				
				$name = str_replace('.feature', '', $file);
				$path = $targetPath . DIRECTORY_SEPARATOR . $file;
				
				$testScenarios = file_get_contents($path);

				// extract the feature name
				preg_match('/^Feature:(.*)/', $testScenarios, $matches);

				$feature = isset($matches[1]) ? trim($matches[1]) : null;
				if (!empty($feature)) {
					$features[$file] = $feature;
				}

				
			}
		}
		
		return $features;

	}
}

/**
 * Single class to generate interface snippets for a feature file template
 */
class Behat_Service_GenerateFeatureTemplateInterface 
{

	/**
	 * @var Behat_Parameter_Factory
	 */
	private $parameterFactory = null;

	/**
	 * @var Behat_Parameter_Parser
	 */
	private $parameterParser = null;

	/**
	 * @param Behat_Parameter_Factory $parameterFactory
	 * @param Behat_Parameter_Parser $parameterParser
	 */
	public function __construct(Behat_Parameter_Factory $parameterFactory, Behat_Parameter_Parser $parameterParser) 
	{
		$this->parameterFactory = $parameterFactory;
		$this->parameterParser = $parameterParser;
	}

	/**
	 * @param Behat_Feature_File $featureFile The feature file to generate an interface
	 *
	 * @return string The feature file interface
	 */
	public function execute(Behat_Feature_File $featureFile) 
	{
		
		$testScenarios = $featureFile->getTestScenarios();

		$parameters = $this->parameterParser->extractParameters($testScenarios);

		$featureInterface = $this->generateFeatureInterface($parameters);
		
		return $featureInterface;
	}

	/**
	 * Generate interface snippets for feature file template parameters
	 *
	 * @param array @parameters The extracted parameters from the feature file
	 */
	private function generateFeatureInterface($parameters) 
	{
		
		$formElements = array();

		foreach ($parameters as $parameter => $meta) {

			$parameterType = isset($meta['type']) ? $meta['type'] : 'text';

			// create a class which can render the specific parameter's interface
			$parameterInterface = $this->parameterFactory->create($parameterType);

			if ($parameterInterface instanceof Behat_Parameter_Interface) {
				$formElements[] = $parameterInterface->render($parameter, $meta['type'], $meta['name'], $meta);
			}

		}

		$featureInterface = implode('', $formElements);

		return $featureInterface;
	}

}

/**
 * An interface to parse parameter information
 */
interface Behat_Parameter_Parser {

	/**
	 * @param string $source The source to parse for parameter information
	 *
	 * @return array
	 */
	public function extractParameters($source);
}

/**
 * Class to parse parameter information in a phpdoc style
 */
class Behat_Parameter_Parser_Phpdoc 
	implements Behat_Parameter_Parser 
{

	/**
	 * @param string $source The source to parse for parameter information
	 *
	 * @return array
	 */
	public function extractParameters($source) {
		
		// Regex pattern matches 
		// 1: parameter type, such as 'text', 'number', 'date', etc.
		// 2: parameter variable, which must match exactly to the feature file's parameter
		// 3: parameter label, which can have spaces and should be shown on the interface
		// 4: parameter description, which should explain about the parameter's usage or meaning;
		//    this can also include a default value for the parameter's interface
		//
		preg_match_all('/# @param (\w*) (\w*) \"([\w\s]*)\"(.*)/m', $source, $matches);

		$parameters = array();

		$items = isset($matches[2]) ? $matches[2] : array();
		foreach($items as $i => $parameter) {

			$source = isset($matches[0][$i]) ? $matches[0][$i] : null;
			$name = isset($matches[3][$i]) ? trim($matches[3][$i]) : null;
			$type = isset($matches[1][$i]) ? $matches[1][$i] : 'text';
			$description = isset($matches[4][$i]) ? trim($matches[4][$i]) : null;

			$default = null;
			if (!empty($description)) {
				preg_match ('/\((.*)\)(.*)/mi', $description, $parts);
				if (isset($parts[1])) {
					$default = trim($parts[1]);
				}
				if (isset($parts[2])) {
					$description = trim($parts[2]);
				}
			}

			$parameters[$parameter] = array(
				'key' => $parameter,
				'name' => $name,
				'type' => $type,
				'default' => $default,
				'description' => $description,
				'source' => $source,
			);

		}

		return $parameters;
	}

}

/**
 * Factory to create parameter classes from a string type
 */
class Behat_Parameter_Factory {

	/**
	 * @param string $type
	 *
	 * @return Behat_Parameter_Interface
	 */
	public function create($type) {

		$parameterInterface = null;

		switch($type) {
			case 'yesno':
				$parameterInterface = new Behat_Parameter_YesNo();
				break;
			default:
				$parameterInterface = new Behat_Parameter_Generic();
				break;
		}

		return $parameterInterface;
	}

}

/**
 * An interface for parameter classes to render HTML
 */
interface Behat_Parameter_Interface {

	/**
	 * Render an HTML interface for the parameter
	 *
	 * @param string $key The parameter key, as per the feature file definition's
	 * @param string $type The parameter type, which generally encapsulated within the class's name
	 * @param string $name The parameter's displayable label
	 * @param array $options Other parameter options from the parameter parser
	 *
	 * @return string
	 */
	public function render($key, $type, $name, $options = array());
}

/**
 * Generic parameter class to encapsulate the interface for most parameters
 */
class Behat_Parameter_Generic implements Behat_Parameter_Interface {

	/**
	 * Render a generic textbox form element
	 */
	public function render($key, $type, $name, $options = array())
	{

		$description = isset($options['description']) ? $options['description'] : null;
		$default = isset($options['default']) ? $options['default'] : null;

		$classes = array('behat-form-element');
		if (!is_null($default)) {
			$classes[] = 'behat-has-default-value';
		}

		$element = '<div class="' . implode(' ' , $classes) . '">';
		
		$element .= '<label for="' . $name . '">' . $name . '</label>';

		$value = !is_null($default) ? ' value="' . $default . '"' : '';
		$element .= '<input id="' . $key . '" name="' . $key . '" class="' . $type . '" type="text"' . $value . '/>';
		
		if (!empty($description)) {
			$element .= '<div class="description">' . $description . '</div>';
		}

		$element .= '</div>';

		return $element;
	}
}

/**
 * Parameter interface for a binary option of 'yes'/'no'
 */
class Behat_Parameter_YesNo implements Behat_Parameter_Interface {

	/**
	 * Render a select form element with options of 'yes'/'no'
	 */
	public function render($key, $type, $name, $options = array())
	{

		$description = isset($options['description']) ? $options['description'] : null;
		$default = isset($options['default']) ? $options['default'] : null;

		$classes = array('behat-form-element');
		if (!is_null($default)) {
			$classes[] = 'behat-has-default-value';
		}

		$element = '<div class="' . implode(' ' , $classes) . '">';
		
		$element .= '<label for="' . $name . '">' . $name . '</label>';

		$value = !is_null($default) ? ' value="' . $default . '"' : '';
		$element .= '<select id="' . $key . '" name="' . $key . '" class="' . $type . '"><option value="yes">Yes</option><option value="no">No</option></select>';
		
		if (!empty($description)) {
			$element .= '<div class="description">' . $description . '</div>';
		}

		$element .= '</div>';

		return $element;
	}
}
