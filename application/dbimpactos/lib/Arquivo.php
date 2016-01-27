<?php

class Arquivo {
	
 	const TIPO_NAO_DEFINIDO = 0;
	const TIPO_PROGRAMA     = 1;
	const TIPO_MENU         = 2;	
	const TIPO_FORMULARIO   = 3;
	const TIPO_CLASSE       = 4;
	const TIPO_RPC          = 5;
	const TIPO_FUNC         = 6;	
	const TIPO_DAO          = 7;	
	const TIPO_MODEL        = 8;	

  private $aRequires = array();
  private $aRequerido = array();
  private $aMenus = array();
  private $aOrigemMenu = array();
  private $aArquivosPesquisados = array();
  private $sLog = null;

  private $sWhereArquivosIgnorar = '';

  public function __construct($iArquivo = 0, $aArquivosIgnorar = array()) {

    if ( empty($iArquivo) ) {
      return;
    }

    $oBanco = Banco::getInstancia();
    $this->iArquivo = $iArquivo;

    if ( !empty($aArquivosIgnorar) ) {
      
      foreach ( $aArquivosIgnorar as $sArquivoIgnorar ) {
        $this->sWhereArquivosIgnorar .= " AND arquivo.caminho not like '%$sArquivoIgnorar'";
      }
    }

    /**
     * Busca arquivos requiridos  
     */
    $aDadosRequires = $oBanco->selectAll("
      SELECT * 
        FROM require 
             INNER JOIN arquivo ON arquivo.id = require.arquivo_require
       WHERE require.arquivo = {$iArquivo}
       $this->sWhereArquivosIgnorar
    ");

    foreach ( $aDadosRequires as $oDadosRequire ) {
      $this->aRequires[] = $oDadosRequire;
    }

    /**
     * Busca arquivos que usam, requerem este arquivo 
     */
    $aDadosRequerido = $oBanco->selectAll("
      SELECT * 
        FROM require 
             INNER JOIN arquivo ON arquivo.id = require.arquivo
       WHERE require.arquivo_require = {$iArquivo}
       $this->sWhereArquivosIgnorar
    ");

    foreach ( $aDadosRequerido as $oDadosRequerido ) {
      $this->aRequerido[] = $oDadosRequerido;
    }

    $aMenus = $this->buscarMenu($iArquivo);

    if ( !empty($aMenus) ) {
      foreach ($aMenus as $iMenu) {
        $this->aMenus[$iMenu][] = $iArquivo;
      }
    }

    $this->buscarMenuRecursivo($iArquivo);

    /**
     * Busca log de erros do arquivo
     */
    $aDadosLog = $oBanco->selectAll("SELECT log FROM log WHERE arquivo = {$iArquivo}");
    foreach ( $aDadosLog as $iIndice => $oDadosLog ) {

      if ( $iIndice > 0 ) {
        $this->sLog .= "\n\n";
      }

      $this->sLog .= $oDadosLog->log;
    }
  }

  public function getOrigemMenu() {
    return $this->montarOrigemMenu($this->aOrigemMenu);
  }

  public function getLog() {
    return $this->sLog;
  }

  public function getRequires() {
    return $this->aRequires;
  }

  public function getRequerido() {
    return $this->aRequerido;
  }

  public function getMenus() {
    return $this->aMenus;
  }

  public function buscarMenuRecursivo($iArquivo, &$aOrigemMenu = array()) {

    if ( !empty($this->aArquivosPesquisados[$iArquivo]) ) {
      return false;
    }

    $this->aArquivosPesquisados[$iArquivo] = 1;

    $oBanco = Banco::getInstancia();

    $aDadosRequires = $oBanco->selectAll("
      SELECT distinct arquivo 
        FROM require 
             INNER JOIN arquivo on arquivo.id = require.arquivo
       WHERE arquivo_require = {$iArquivo}
       $this->sWhereArquivosIgnorar
    ");

    if ( count($aDadosRequires) == 0 ) {
      return false;
    }

    foreach ( $aDadosRequires as $oDadosRequire ) {

      $aMenus = $this->buscarMenu($oDadosRequire->arquivo);

      if ( !empty($aMenus) ) {

        foreach ( $aMenus as $iMenu ) {

          $aOrigemMenu[$iArquivo][$oDadosRequire->arquivo] = $iMenu;
          $this->aMenus[$iMenu][] = $oDadosRequire->arquivo;
        }

        continue;
      }

      $aOrigemMenu[$iArquivo][$oDadosRequire->arquivo] = array();
      $this->buscarMenuRecursivo($oDadosRequire->arquivo, $aOrigemMenu[$iArquivo]);  
    }

    $this->aOrigemMenu = $aOrigemMenu;
  }

  public function montarOrigemMenu($aOrigemMenu, $aOrigem = array(), $pai = 0) {

    static $aMenus = array();

    foreach ( $aOrigemMenu as $iArquivo => $mItensMenu ) {

      if ( empty($mItensMenu) ) {
        continue;
      }

      $aOrigem[$pai] = $iArquivo;

      if ( is_scalar($mItensMenu) ) {

        $aMenus[$mItensMenu][] = array_reverse($aOrigem);
        continue;
      }

      $this->montarOrigemMenu($mItensMenu, $aOrigem, $iArquivo);
    }

    return $aMenus;
  }

  public function buscarMenu($iArquivo) {

    $aMenus = array();
    $oBanco = Banco::getInstancia();
    $aDadosMenu = $oBanco->selectAll("SELECT menu FROM menu_arquivo WHERE menu_arquivo.arquivo = $iArquivo");

    foreach ( $aDadosMenu as $oDadosMenu ) {
      $aMenus[] = $oDadosMenu->menu; 
    }

    return $aMenus;
  }

  public static function clearPath($sCaminhoArquivo, $sDiretorioProjeto = '/var/www/dbportal_prj/') {
    return str_replace($sDiretorioProjeto, '', $sCaminhoArquivo);
  }

	public static function buscarTipo($sArquivo) { 
		
		/**
		 * arquivo esta na pasta model
		 */
		if ( strpos($sArquivo, '/var/www/dbportal_prj/model') === 0 ) {
			return Arquivo::TIPO_MODEL;
		}
		
		/**
		 * arquivo esta na pasta classes
		 */
		if ( strpos($sArquivo, '/var/www/dbportal_prj/classes') === 0 ) {
			return Arquivo::TIPO_DAO;
		}
		
		/**
		 * arquivo esta na pasta formularios
		 */
		if ( strpos($sArquivo, '/var/www/dbportal_prj/forms') === 0 ) {
			return Arquivo::TIPO_FORMULARIO;
		}
		
		/**
		 * RPC
		 */
		if ( strpos(strtoupper(basename($sArquivo)), 'RPC') !== false ) {
			return Arquivo::TIPO_RPC;
		}
		
		/**
		 * Funcao de pesquisa
		 */
		if ( strpos(basename($sArquivo), 'func_') === 0 ) {
			return Arquivo::TIPO_FUNC;
		}

		if ( strpos($sArquivo, '/var/www/dbportal_prj/' . basename($sArquivo)) === 0 ) {
		  return Arquivo::TIPO_PROGRAMA;		
    }
		
		return Arquivo::TIPO_NAO_DEFINIDO;		
	}

  public static function getFuncoes($iArquivo) {
    return Banco::getInstancia()->selectAll("SELECT * FROM funcao WHERE arquivo = {$iArquivo}");
  }

  public static function getConstantes($iArquivo) {
    return Banco::getInstancia()->selectAll("SELECT * FROM constant WHERE arquivo = {$iArquivo}");
  }

  public static function getClasses($iArquivo) {
   
    $aClasses = array();
    $aDadosClasses = Banco::getInstancia()->selectAll("SELECT id, nome FROM classe WHERE arquivo = {$iArquivo}");

    foreach ( $aDadosClasses as $oDadosClasse ) {

      $aClasses[$oDadosClasse->nome] = array(); 
      $aMetodos = Banco::getInstancia()->selectAll("SELECT nome FROM metodo WHERE classe = {$oDadosClasse->id}");

      foreach ( $aMetodos as $oDadosMetodo ) {
        $aClasses[$oDadosClasse->nome]['method'] = $oDadosMetodo->nome;
      }

      $aConstants = Banco::getInstancia()->selectAll("SELECT nome FROM classe_constant WHERE classe = {$oDadosClasse->id}");

      foreach ( $aConstants as $oDadosConstant ) {
        $aClasses[$oDadosClasse->nome]['constant'] = $oDadosConstant->nome;
      }

    }

    return $aClasses;
  }

  public static function getArquivos($sDiretorio) {

    $aArquivos = array();
    $oDiretorio = new RecursiveDirectoryIterator($sDiretorio);
    $oDiretorioIterator = new RecursiveIteratorIterator($oDiretorio, RecursiveIteratorIterator::SELF_FIRST);

    foreach($oDiretorioIterator as $sCaminhoArquivo => $oArquivo) {

      if ( $oArquivo->isDir() ) {
        continue;
      }

      if ( !in_array($oArquivo->getExtension(), array('php', 'js', 'class')) ) {
        continue;
      }

      if ( in_array($oArquivo->getFileName(), array('.', '..')) ) {
        continue;
      }

      $aArquivos[] = $sCaminhoArquivo;
    } 

    return $aArquivos;
  }

}
