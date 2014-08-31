<?php
/**
 * @package     JSpace.Plugin
 *
 * @copyright   Copyright (C) 2014 KnowledgeArc Ltd. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */
 
defined('_JEXEC') or die;

jimport('joomla.registry.registry');

jimport('jspace.ingestion.harvest');

/**
 * Handles importing items via an OpenSearch compliant search engine.
 *
 * @package     JSpace.Plugin
 */
class PlgContentHarvest extends JPlugin
{
    public function __construct($subject, $config = array())
    {   
        parent::__construct($subject, $config);
        $this->loadLanguage();
        
        JLog::addLogger(array());
    }
    
    public function onJSpaceExecuteCliCommand($commands = array(), $options = array())
    {
        $this->params->loadArray(array('args'=>$options));

        $application = JFactory::getApplication('cli');

        $help = ($this->params->get('args.h') || $this->params->get('args.help'));

        $command = JArrayHelper::getValue($commands, 0, 'harvest');

        try
        {
            if ($help)
            {
                $this->_help();
            }
            else if ($command)
            {
                switch ($command)
                {
                    case 'list':
                        $this->_list();
                        break;
                        
                    case 'harvest':
                        $this->_harvest();
                        break;
                        
                    default: // if the command doesn't exist, print help.
                        $this->_help();
                        break;
                }
            }
        }
        catch (Exception $e)
        {
            $this->out($e->getMessage());
        }
    }
    
    private function _list()
    {
        $database = JFactory::getDbo();
    
        $select = array(
            $database->qn('id'),
            $database->qn('originating_url'),
            $database->qn('frequency'),
            $database->qn('params')
        );
    
        $query = $database->getQuery(true);
        $query
            ->select($select)
            ->from($database->qn('#__jspace_harvests'), 'a');
            
        $results = $database->setQuery($query)->loadObjectList();
        
        foreach ($results as $result)
        {
            $params = new JRegistry;
            $params->loadString($result->params);
        
            $this->out(
                $result->id."\t".
                $params->get('discovery.url'));
        }
    }
    
    private function _harvest()
    {
        $database = JFactory::getDbo();
    
        $query = $database->getQuery(true);
        $query
            ->select($database->qn('id'))
            ->from($database->qn('#__jspace_harvests', 'h'))
            ->where($database->qn('h.frequency').'=0', 'OR')
            ->where($database->qn('h.frequency').'>'.$database->qn('h.total'));

        $results = $database->setQuery($query)->loadObjectList();
        
        $start = new JDate('now');
        $this->out('started '.(string)$start);

        $dispatcher = JEventDispatcher::getInstance();
        JPluginHelper::importPlugin('jspace');
        
        foreach ($results as $result)
        {
            try
            {
                $now = new JDate('now');
                
                $harvest = JSpaceIngestionHarvest::getInstance($result->id);
                $dispatcher->trigger('onJSpaceHarvestRetrieve', array($harvest));
                $dispatcher->trigger('onJSpaceHarvestIngest', array($harvest));
                
                $harvest->harvested = $now->toSql();
                $harvest->total++;
                $harvest->save();
            }
            catch (Exception $e)
            {
                echo $e->getMessage();
                echo $e->getTraceAsString();
                $this->out($e->getMessage());
            }
        }
        
        $end = new JDate('now');
        
        $this->out('ended '.(string)$end);
        $this->out($start->diff($end)->format("%H:%I:%S"));
    }
    
    /**
     * Prints out the plugin's help and usage information.
     *
     */
    private function _help()
    {
        $out = <<<EOT
Usage: jspace harvest [OPTIONS] [COMMAND]

Provides harvesting functions from the command line.

Running harvest without [OPTIONS] and/or [COMMAND] will process the 
entire list of valid harvests.

[COMMAND]
  list                       Lists all available harvests.
  
[OPTIONS]
  -h, --help                 Prints this help.
  -q, --quiet                Suppress all output including errors.
  
EOT;

        $this->out($out);
    }
    
    public function out($out)
    {
        $application = JFactory::getApplication('cli');
        
        if (get_class($application) !== 'JApplicationCli')
        {
            return;
        }
 
        if (!$this->params->get('args.q') && !$this->params->get('args.quiet'))
        {
            $application->out($out);
        }
    }
}