<?php
require_once __DIR__ . '/Tokenizer.php';

/**
 * File Parser
 *
 * @todo - criar arquivo para parse de php e outro para javascript
 * 
 * @author Jeferson Belmiro <jeferson.belmiro@gmail.com>
 * @license MIT
 */
class FileParser {

  protected $code;
  protected $tokenizer;
  protected $pathFile;
  protected $pathRequire;
  protected $totalLines = 0;

  protected $classes = array();
  protected $functions = array();
  protected $requires = array();
  protected $declaring = array();
  protected $constants = array();

  protected $constantsUsed = array();
  protected $functionsUsed = array();

  protected $log = "";
  protected $currentClassName;
  protected $brackets;

  const FILE_PATTERN = '/\b(?P<files>[\/\w-.]+\.php)\b/mi';

  public function __construct($pathFile, $pathProject = '/var/www/dbportal_prj/') {

    if ( !file_exists($pathFile) ) {
      throw new Exception("File {$pathFile} not exists.");
    }

    $this->pathFile = $pathFile;
    $this->pathRequire = dirname($pathFile) . '/';
    $this->pathProject = $pathProject;

    $file = fopen($pathFile, "r"); 

    while ( !feof($file) ) { 

      $this->code .= fgets($file, 4096); 
      $this->totalLines++;
    } 

    fclose($file); 

    if ( empty($this->code) ) {
      throw new Exception("File {$pathFile} is empty.");
    }

    $this->tokenizer = new Tokenizer($this->code);
    $this->parse();
  }

  public function parse() {

    $tokenizer = $this->tokenizer;
    $this->rewind();

    while ($tokenizer->valid()) {

      switch ($tokenizer->current()->getValue()) {

        case $tokenizer->current()->isOpeningBrace() :
          $this->brackets++;
        break;

        case $tokenizer->current()->isClosingBrace() :
          $this->brackets--;
        break;
          
        case T_REQUIRE:
        case T_INCLUDE: 
        case T_REQUIRE_ONCE:
        case T_INCLUDE_ONCE:
          $this->parseRequire();
        break;

        case T_NEW :
          $this->parseDeclaring();
        break;
          
        case T_CLASS :
          $this->parseClass();
        break;

        case T_FUNCTION:
          $this->parseFunction();
        break;
        
        case T_CONST:
          $this->parseConstant();
        break;
        
        case T_CONSTANT_ENCAPSED_STRING:
        case T_ENCAPSED_AND_WHITESPACE:
          $this->parseEncapsedString();
        break;
        
        case T_INLINE_HTML:
          $this->parseHTML();
        break;
        
        case T_STRING:
          $this->parseString();
        break;
      }

      $tokenizer->next();
    }
    
  }

  public function rewind() {

    $this->tokenizer->rewind();
    $this->brackets = 0;
  }

  public function getNextToken($indexSeek = 1) {

    $tokenizer = clone $this->tokenizer;
    $tokenizer->seek($tokenizer->key() + $indexSeek);

    if ($tokenizer->current()->is(T_WHITESPACE)) {
      $tokenizer->next();
    } 

    return $tokenizer->current();
  }

  public function getPrevToken($indexSeek = 1) {

    $tokenizer = clone $this->tokenizer;
    $tokenizer->seek($tokenizer->key() - $indexSeek);

    if ($tokenizer->current()->is(T_WHITESPACE)) {
      $tokenizer->prev();
    } 

    return $tokenizer->current();
  }

  public function findToken($find, $offset = 0, $tokenStop = ';', $direction = Tokenizer::FIND_TOKEN_FORWARD) {

    $tokens = array($tokenStop, $find);

    if (is_array($find)) {

      $tokens = $find;
      array_unshift($tokens, $tokenStop);
    }

    $index = $this->tokenizer->findToken($tokens, $offset, $direction);

    if (!$index) {
      return false;
    }

    if ($this->tokenizer->offsetGet($index)->is($tokenStop)) {
      return false;
    }

    return $index; 
  }

  public function findTokenForward($find, $tokenStop = ';') {
    return $this->findToken($find, $this->tokenizer->key(), $tokenStop, Tokenizer::FIND_TOKEN_FORWARD);
  }

  public function findTokenBackward($find, $tokenStop = ';') {
    return $this->findToken($find, $this->tokenizer->key(), $tokenStop, Tokenizer::FIND_TOKEN_BACKWARD);
  }

  public function clearEncapsedString($string) {
    return str_replace(array('"', "'"), '', $string);
  }

  public function getFileName($string) {

    if ( strpos($string, '.php') === false ) {
      return false;
    }

    preg_match_all(FileParser::FILE_PATTERN, $string, $matches);
    return $matches['files'];
  }

  public function parseRequire() {

    $index = $this->findTokenForward(array(T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE));

    if (!$index) {
      return false; 
    }

    $this->tokenizer->seek($index);
    $token = $this->tokenizer->offsetGet($index);
    $line = $token->getLine();
    $require = $this->clearEncapsedString($token->getCode());
    $requireFile = $this->pathRequire . $require; 

    if ( !file_exists($requireFile) ) {
      $requireFile = $this->pathProject . $require; 
    } 

    $requireFile = realpath($requireFile);

    if ( empty($requireFile) ) {

      $this->log .= "\n ----------------------------------------------------------------------------------------------------\n";
      $this->log .= " FileParser::parseRequire(): " . $this->pathFile . " : " . $line;
      $this->log .= "\n - Arquivo de require não encontrado: ";
      $this->log .= "\n " . $this->pathRequire . $require ."  || " . $this->pathProject . $require;
      $this->log .= "\n ----------------------------------------------------------------------------------------------------\n";
      return false;
    }

    $this->requires[] = array('line' => $line, 'file' => $requireFile); 
    return true;
  }

  public function parseDeclaring() {

    $index = $this->findTokenForward(T_STRING);

    if (!$index) {
      return false; 
    }

    $this->tokenizer->seek($index);
    $token = $this->tokenizer->offsetGet($index);

    if (in_array(strtolower($token->getCode()), array('stdclass', 'exception'))) {
      return false;
    } 

    $this->declaring[] = array('line' => $token->getLine(), 'class' => $token->getCode());
    return true;
  }

  public function parseClass() {

    $index = $this->findTokenForward(T_STRING);

    if (!$index) {
      return false; 
    }

    $this->tokenizer->seek($index);
    $token = $this->tokenizer->offsetGet($index);
    $this->currentClassName = $token->getCode();
    $this->classes[$this->currentClassName] = array('line' => $token->getLine(), 'method' => array(), 'constant' => array());
  }

  public function parseFunction() {

    $index = $this->findTokenForward(T_STRING);

    if (!$index) {
      return false; 
    }

    $this->tokenizer->seek($index);
    $token = $this->tokenizer->offsetGet($index);

    if ( $this->brackets === 0 ) {
      $this->currentClassName = null;
    }

    if ( empty($this->currentClassName) ) {

      $this->functions[] = array('line' => $token->getLine(), 'function' => $token->getCode());
      return;
    }

    $this->classes[$this->currentClassName]['method'][] = array('line' => $token->getLine(), 'function' => $token->getCode());
  }

  public function parseConstant() {
    
    $index = $this->findTokenForward(T_STRING);

    if (!$index) {
      return false; 
    }

    $this->tokenizer->seek($index);
    $token = $this->tokenizer->offsetGet($index);

    if ( empty($this->currentClassName) ) {

      $this->constants[] = array('line' => $token->getLine(), 'name' => $token->getCode());
      return true;
    }

    $this->classes[$this->currentClassName]['constant'][] = array('line' => $token->getLine(), 'name' => $token->getCode());
    return true; 
  }

  public function parseConstantDefined() {

    $index = $this->findTokenForward(array(T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE));

    if (!$index) {
      return false; 
    }

    $this->tokenizer->seek($index);
    $token = $this->tokenizer->offsetGet($index);
    $this->constants[] = array('line' => $token->getLine(), 'name' => $this->clearEncapsedString($token->getCode()));
    return true; 
  }

  public function parseEncapsedString() {

    $token = $this->tokenizer->current();
    $string = $token->getCode();
    $line = $token->getLine();
    $files = $this->getFileName($string);

    if ( empty($files) ) {
      return false;
    }

    foreach ( $files as $require ) {

      $requireFile = $this->pathRequire . $require; 

      if ( !file_exists($requireFile) ) {
        $requireFile = $this->pathProject . $require; 
      } 

      $requireFile = realpath($requireFile);

      if ( empty($requireFile) ) {

        $this->log .= "\n ----------------------------------------------------------------------------------------------------\n";
        $this->log .= " FileParser::parseEncapsedString(): " .$this->pathFile . " : " . $line;
        $this->log .= "\n - Arquivo de require não encontrado : ";
        $this->log .= "\n " . $this->pathRequire . $require ."  || " . $this->pathProject . $require;
        $this->log .= "\n ----------------------------------------------------------------------------------------------------\n";
        return false;
      }

      $this->requires[] = array('line' => $line, 'file' => $requireFile);
    }

  }

  // @todo - verificar se é metodo ou constant    
  public function parseStatic() {

    $token = $this->getNextToken();
    $ignore = array('SELF', '__CLASS__', strtoupper($this->currentClassName));

    if (in_array(strtoupper($token->getCode()), $ignore)) {
      return false;
    }

    /**
     * Metodo statico 
     */
    if ($token->is(T_DOUBLE_COLON)) {
      return false;
    }

    $this->declaring[] = array('line' => $token->getLine(), 'class' => $token->getCode());
    return true;
  }

  public function parseHTML() {

    $token = $this->tokenizer->current();
    $string = $token->getCode();
    $lines = explode("\n", $token->getCode());
    $currentLine = $token->getLine();

    foreach ($lines as $contentLine) {

      $files = $this->getFileName($contentLine);

      if ( empty($files) ) {

        $currentLine++;
        continue;
      }

      foreach ( $files as $require ) {

        $requireFile = $this->pathRequire . $require; 

        if ( !file_exists($requireFile) ) {
          $requireFile = $this->pathProject . $require; 
        } 

        $requireFile = realpath($requireFile);

        if ( empty($requireFile) ) {

          $this->log .= "\n ----------------------------------------------------------------------------------------------------\n";
          $this->log .= " - Arquivo de require não encontrado: " . $this->pathFile . " :" . $require;
          $this->log .= "\n   FileParser::parseHTML(): ";
          $this->log .= "\n   " . $this->pathRequire . $require ."  || " . $this->pathProject . $require;
          $this->log .= "\n ----------------------------------------------------------------------------------------------------\n";
          return false;
        }

        $this->requires[] = array('line' => $currentLine, 'file' => $requireFile);
      }

      $currentLine++;
    } 
  }

  public function parseString() {

    /**
     * Define constant
     */
    if ($this->tokenizer->current()->is('define')) {
      return $this->parseConstantDefined();
    }

    /**
     * Static method or constant 
     */
    if ($this->getNextToken()->is(T_DOUBLE_COLON)) {
      return $this->parseStatic();
    }
      
    /**
     * Function
     */
    if ($this->getNextToken()->is('(')) {
      return $this->parseFunctionUsed();
    }

    if ($this->findTokenBackward(T_CATCH)) {
      return false;
    }

    if ($this->findTokenBackward(T_OBJECT_OPERATOR)) {
      return false;
    }

    /**
     * Constant 
     */
    return $this->parseConstantUsed();
  }

  public function parseConstantUsed() {

    $code = $this->tokenizer->current()->getCode();

    if ( $code == 'janela') {

      print_r($this->getPrevToken(3));
      print_r($this->getPrevToken(2));
      print_r($this->getPrevToken());

      print_r($this->tokenizer->current());

      print_r($this->getNextToken());
      print_r($this->getNextToken(2));
      print_r($this->getNextToken(3));
      exit;
    }

    if (in_array(strtoupper($code), array('FALSE', 'TRUE', 'NULL')) ) { 
      return false;
    }

    if (in_array($code, $this->constantsUsed)) {
      return false;
    }

    $this->constantsUsed[] = $code;
    return true; 
  }

  public function parseFunctionUsed() {

    $code = $this->tokenizer->current()->getCode();

    if (in_array($code, $this->functionsUsed)) {
      return false;
    }

    $this->functionsUsed[] = $code;
    return true; 
  }


  public function getClasses() {
    return $this->classes;
  }

  public function getFunctions() {
    return $this->functions;
  }

  public function getRequires() {
    return $this->requires;
  }

  public function getDeclaring() {
    return $this->declaring;
  }

  public function getConstants() {
    return $this->constants;
  }

  public function getConstantsUsed() {
    return $this->constantsUsed;
  }

  public function getFunctionsUsed() {
    return $this->functionsUsed;
  }

  public function getLog() {
    return $this->log;
  }

  public function getTotalLines() {
    return $this->totalLines;
  }

}

// $file = new FileParser('/var/www/dbportal_prj/model/dataManager.php');
// $file = new FileParser('/var/www/dbportal_prj/pes2_cadferiasmes001.php');
$file = new FileParser('/var/www/dbportal_prj/libs/db_stdlib.php');
 
echo "\ncontants: \n";
print_r($file->getConstants());
echo "\nclasses: \n";
print_r($file->getClasses());
echo "\nfunctions: \n";
print_r($file->getFunctions());
echo "\nrequires: \n";
print_r($file->getRequires());
echo "\ndeclaring: \n";
print_r($file->getDeclaring());
echo "\nfunction used: \n";
print_r($file->getFunctionsUsed());
echo "\nconstants used: \n";
print_r($file->getConstantsUsed());
echo "\nlog: \n";
print_r($file->getLog());
