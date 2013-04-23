<?php

/*
 * Copyright (C) 2013 Pierre-Gildas MILLON <pg.millon@gmail.com>.
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 * MA 02110-1301  USA
 */

require_once 'vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Zend\Mail;
use Zend\Mime;
use Zend\Config\Config;
use Zend\Config\Reader;
use Zend\Config\Processor;

class ScoreCommand extends Command {

  /**
   *
   * @var Zend\Config\Config
   */
  protected $config;
  
  protected function configure() {
    $this->setName("git:score");
    $this->setDescription("Score points from git log");
    
    $this->config = $this->readConfig();
  }
  
  protected function readConfig() {
    $configReader = new Reader\Yaml(array('Symfony\Component\Yaml\Yaml', 'parse'));
    $configArray  = $configReader->fromFile(__DIR__ . '/config.yml');
    $iterator     = new RecursiveIteratorIterator(new RecursiveArrayIterator($configArray));
    $config       = new Config(array(), true);
    $processor    = new Processor\Token(array(
      'APPLICATION_PATH' => __DIR__
    ));
    
    foreach($iterator as $key => $value) {
      $paths = array();
      if(is_numeric($key)) {
        foreach(range(0, $iterator->getDepth() - 1) as $depth) {
          $paths[] = $iterator->getSubIterator($depth)->key();
        }
        if(isset($config[join('_', $paths)])) {
          $config[join('_', $paths)][$key] = $value;
        } else {
          $config[join('_', $paths)] = array($key => $value);
        }
      } else {
        foreach(range(0, $iterator->getDepth()) as $depth) {
          $key = $iterator->getSubIterator($depth)->key();
          if($key === '.array') {
            $arrayValue = $value;
            foreach(range($iterator->getDepth(), $depth + 1) as $reverseDepth) {
              $reverseKey = $iterator->getSubIterator($reverseDepth)->key();
              $arrayValue =  array(
                $reverseKey => $arrayValue
              );
            }
            $value = $arrayValue;
            break;
          } else {
            $paths[] = $key;
          }
        }
        $finalPath = join('_', $paths);
        if(isset($config[$finalPath]) && $config[$finalPath] instanceof ArrayAccess) {
          $config[$finalPath] = array_merge_recursive($config[$finalPath]->toArray(), $value);
        } else {
          $config[$finalPath] = $value;
        }
      }
    };
    
    $processor->process($config);
    
    return $config;
  }
  
  protected function getDataFilename() {
    $dataDir = $this->config['data_dir'];
    $fileName = $this->config['data_filename'];
    
    return $dataDir.DIRECTORY_SEPARATOR.$fileName;
  }


  protected function loadData() {
    $filename = $this->getDataFilename();
    if(is_readable($filename)) {
      return json_decode(file_get_contents($filename), true);
    } else {
      return array();
    }
  }
  
  protected function saveData($scores) {
    $dataDir = $this->config['data_dir'];
    
    if(!file_exists($dataDir)) {
      mkdir($dataDir, 0755, true);
    }
    
    file_put_contents($this->getDataFilename(), json_encode($scores));
  }
  
  protected function sendEmail($scores) {
    $twig   = new Twig_Environment(new Twig_Loader_Filesystem($this->config['template_dir']));
    
    $htmlMsg = new Mime\Part($twig->render('email/scores.twig', array('scores' => $scores)));
    $htmlMsg->type = 'text/html; charset=utf-8';
    
    $body = new Mime\Message();
    $body->setParts(array($htmlMsg));
    
    $mail = new Mail\Message();
    $mail->setSubject($this->config['email_subject']);
    $mail->setFrom($this->config['email_sender']);
    $mail->addTo($this->config['email_to']);
    $mail->setBody($body);
    
    $transport = new Mail\Transport\Smtp();
    $transportOptions = new Mail\Transport\SmtpOptions($this->config['smtp']);
    $transport->setOptions($transportOptions);
    
    $transport->send($mail);
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $handle = fopen('php://stdin', 'r');
    $scores = $this->loadData();
    
    
    while(($data = fgetcsv($handle, 0, ';')) !== false) {
      list($email, $commitMsg) = $data;
      
      if(preg_match('/^\w{5}-.*/', $commitMsg) > 0) {
        $scores[$email] = isset($scores[$email]) ? ++$scores[$email] : 1;
      } else {
        $scores[$email] = isset($scores[$email]) ? --$scores[$email] : -1;
      }
    }
    
    $this->saveData($scores);
    $this->sendEmail($scores);
  }
  
}

class ScoringApplication extends Application {
  
  public function __construct($name = 'UNKNOWN', $version = 'UNKNOWN') {
    parent::__construct($name, $version);
    
    $this->add(new ScoreCommand());
  }
  
  public static function execute() {
    $app = new ScoringApplication();
    $app->run();
  }
  
}

ScoringApplication::execute();