<?php
namespace DBReleases;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Exception;

class DBReleasesCommand extends Command {

  /**
   * Configura o comando
   *
   * @access public
   * @return void
   */
  public function configure() {

    $this->setName('dbreleases');
    $this->setDescription('Tag das releases');
    $this->setHelp('Tag das releases');
    $this->addOption('edit', 'e', InputOption::VALUE_NONE, 'Editar arquivo com releases');
  }

  /**
   * Executa o comando
   *
   * @param Object $oInput
   * @param Object $oOutput
   * @access public
   * @return void
   */
  public function execute($oInput, $oOutput) {

    $sArquivo = '/media/equipe/dev/Sprints/TimeB/Utils/dbreleases';

    if ( !file_exists($sArquivo) ) {
      throw new Exception('Não foi possivel acessar arquivo: ' . $sArquivo);
    }

    if ( $oInput->hasParameterOption(array('--edit', '-e')) ) { 
      $this->editar($sArquivo);
    }

    $sConteudoArquivo = file_get_contents($sArquivo);
    $oOutput->writeln("\n  " . str_replace("\n", "\n  ", $sConteudoArquivo));
  }

  private function editar($sArquivo) {
    return pcntl_exec('/usr/bin/vim', array($sArquivo));
  }

}
