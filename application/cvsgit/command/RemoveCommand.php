<?php
namespace CVS;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class RemoveCommand extends Command {

  public function configure() {

    $this->setName('rm');
    $this->addArgument('arquivos', InputArgument::IS_ARRAY, 'Arquivos para remover');
    $this->setDescription('Remove arquivos da lista de commit');
    $this->setHelp('Remove arquivos da lista de commit');
  }

  public function execute($oInput, $oOutput) {

    $aArquivos = $this->getApplication()->getArquivos();
    $aArquivosInformados = $oInput->getArgument('arquivos');
    $aArquivosRemover = array();

    if ( empty($aArquivos) ) {

      $oOutput->writeln('<error>Nenhum arquivo para remover</error>');
      return 1;
    } 

    foreach( $aArquivosInformados as $sArquivo ) {

      if ( empty($aArquivos[$sArquivo]) && empty($aArquivos[getcwd() . '/' . $sArquivo]) ) {

        $oOutput->writeln("<error>Arquivo não encontrado na lista: $sArquivo</error>");
        continue;
      }

      $aArquivosRemover[] = $sArquivo;
    }

    /**
     * Nenhum arquivo informado, pergunta se pode remover todos 
     *
     */
    if ( empty($aArquivosInformados) ) {

      $oDialog = $this->getHelperSet()->get('dialog');
      $sRemoverTodos = $oDialog->ask($oOutput, 'Nenhum arquivo inforamado, remover todos da lista? (s/N): ');

      if ( strtoupper($sRemoverTodos) == 'S' ) {
        $aArquivosRemover = array_keys($aArquivos);
      }
    }

    if ( !empty($aArquivosRemover) ) {

      $lRemovido = $this->getApplication()->removerArquivos($aArquivosRemover);

      foreach( $aArquivosRemover as $sArquivoRemovido ) {
        $oOutput->writeln("<info>Arquivo removido da lista: $sArquivoRemovido</info>");
      }
    }
  }

}
