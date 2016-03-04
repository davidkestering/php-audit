<?php
//----------------------------------------------------------------------------------------------------------------------
namespace SetBased\Audit;

use Monolog\Formatter\LineFormatter;
use SetBased\Audit\Exception\RuntimeException;
use SetBased\Audit\MySql\DataLayer;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

//----------------------------------------------------------------------------------------------------------------------
/**
 * Class.
 */
class Audit
{
  //--------------------------------------------------------------------------------------------------------------------.
  /**
   * Logger.
   *
   * @var Logger
   */
  private $myLog;

  /**
   * All config file as array.
   *
   * @var array
   */
  private $myConfig;

  /**
   * If true remove all column information from config file.
   *
   * @var boolean
   */
  private $myPruneOption;

  /**
   * Array of tables from audit schema.
   *
   * @var array
   */
  private $myAuditSchemaTables;

  /**
   * Array of tables from data schema.
   *
   * @var array
   */
  private $myDataSchemaTables;

  /**
   * Config file name.
   *
   * @var string
   */
  private $myConfigFileName;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Main function.
   *
   * @param string[] $theOptions Option from console on running script
   *
   * @return int
   *
   */
  public function main($theOptions)
  {
    $this->myPruneOption = $theOptions['prune'];

    // Initialize monolog, set custom output for LineFormatter
    // Set Logger levels from console commands {-v, -d}
    $output    = "[%datetime%] %message%\n";
    $formatter = new LineFormatter($output);

    $logger_level = Logger::NOTICE;
    if ($theOptions['verbose']) $logger_level = Logger::INFO;
    if ($theOptions['debug']) $logger_level = Logger::DEBUG;

    $streamHandler = new StreamHandler('php://stdout', $logger_level);
    $streamHandler->setFormatter($formatter);
    $this->myLog = new Logger('AUDIT');
    $this->myLog->pushHandler($streamHandler);
    $streamHandler->setFormatter($formatter);

    // Read config file name, config content and then save in variable
    $this->myConfigFileName = $theOptions['config'];
    $this->readConfigFile($this->myConfigFileName);

    // Create database connection with params from config file
    DataLayer::connect($this->myConfig['database']['host_name'], $this->myConfig['database']['user_name'],
                       $this->myConfig['database']['password'], $this->myConfig['database']['data_schema']);
    DataLayer::setLog($this->myLog);

    $this->listOfTables();

    $this->compareTables();

    $this->compareAuditTables();

    $this->getColumns();

    $this->createMissingAuditTables();

    $this->createTriggers();

    // Drop database connection
    DataLayer::disconnect();

    return 0;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Create SQL code for trigger for all tables
   *
   * @param string $theTableName The name of table
   * @param string $theAction    Trigger ON action {INSERT, DELETE, UPDATE}
   */
  public function createTableTrigger($theTableName, $theAction)
  {
    $this->logVerbose("Create {$theAction} trigger for table {$theTableName}.");
    $trigger_name = $this->getTriggerName($this->myConfig['database']['data_schema'], $theAction);
    DataLayer::createTrigger($this->myConfig['database']['data_schema'],
                             $this->myConfig['database']['audit_schema'],
                             $theTableName,
                             $theAction,
                             $trigger_name);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Log function
   *
   * @param string $theMessage Message for print in console
   */
  public function logInfo($theMessage)
  {
    $this->myLog->addNotice($theMessage);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Log verbose
   *
   * @param string $theMessage Message for print in console
   */
  public function logVerbose($theMessage)
  {
    $this->myLog->addInfo($theMessage);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Drop trigger from table.
   *
   * @param string $theDataSchema Database data schema
   * @param string $theTableName  Name of table
   */
  public function dropTriggers($theDataSchema, $theTableName)
  {
    $old_triggers = DataLayer::getTableTriggers($theDataSchema, $theTableName);

    foreach ($old_triggers as $trigger)
    {
      $this->logVerbose("Drop trigger {$trigger['Trigger_Name']} for table {$theTableName}.");
      DataLayer::dropTrigger($trigger['Trigger_Name']);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Create and return trigger name.
   *
   * @param string $theDataSchema Database data schema
   * @param string $theAction     Trigger on action (Insert, Update, Delete)
   *
   * @return string
   */
  public function getTriggerName($theDataSchema, $theAction)
  {
    $uuid = uniqid('trg_');

    return strtolower("`{$theDataSchema}`.`{$uuid}_{$theAction}`");
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Call function for creating triggers for all tables in rank_data schema
   */
  public function createTriggers()
  {
    foreach ($this->myDataSchemaTables as $table)
    {
      if ($this->myConfig['tables'][$table['table_name']])
      {
        $this->dropTriggers($this->myConfig['database']['data_schema'], $table['table_name']);
        $this->createTableTrigger($table['table_name'], 'INSERT');
        $this->createTableTrigger($table['table_name'], 'UPDATE');
        $this->createTableTrigger($table['table_name'], 'DELETE');
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns an array with missing tables in the audit schema.
   *
   * @return string[]
   */
  public function findMissingTables()
  {
    $missing_tables = [];

    foreach ($this->myDataSchemaTables as $table)
    {
      if ($this->myConfig['tables'][$table['table_name']])
      {
        $res = DataLayer::searchInRowSet('table_name', $table['table_name'], $this->myAuditSchemaTables);
        if (!isset($res))
        {
          $missing_tables[] = $table;
        }
      }
    }

    return $missing_tables;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Create missing tables in audit schema
   */
  public function createMissingAuditTables()
  {
    $missing_tables = $this->findMissingTables();

    foreach ($missing_tables as $table)
    {
      $this->logInfo("Creating audit table {$table['table_name']}.");
      $columns = $this->getMergeColumns($table['table_name'], true);
      DataLayer::generateSqlCreateStatement($this->myConfig['database']['audit_schema'], $table['table_name'], $columns);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Compares the tables listed in the config file and the tables found in the audit schema
   */
  public function getColumns()
  {
    $this->myConfig['table_columns'] = [];
    foreach ($this->myConfig['tables'] as $table_name => $flag)
    {
      if ($flag)
      {
        $columns = $this->columnsOfTable($table_name);
        foreach ($columns as $column)
        {
          $this->myConfig['table_columns'][$table_name][$column['column_name']] = $column['data_type'];
        }
      }
    }
    if ($this->myPruneOption==1)
    {
      $this->myConfig['table_columns'] = [];
    }
    $this->rewriteConfig();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Generate array with audit columns and columns from data table.
   *
   * @param string  $theTableName        Name of table
   * @param boolean $theMissingTableFlag Check if table is missing in audit
   *
   * @return array
   */
  public function getMergeColumns($theTableName, $theMissingTableFlag)
  {
    $columns = [];
    foreach ($this->myConfig['audit_columns'] as $column)
    {
      $columns[] = ['name' => $column['name'], 'type' => $column['type']];
    }
    if ($theMissingTableFlag)
    {
      $miss_columns = $this->columnsOfTable($theTableName);
      foreach ($miss_columns as $column)
      {
        if ($column['data_type']!='timestamp')
        {
          $columns[] = ['name' => $column['column_name'], 'type' => $column['data_type'].' DEFAULT NULL'];
        }
        else
        {
          $columns[] = ['name' => $column['column_name'], 'type' => $column['data_type'].' NULL'];
        }
      }
    }
    else
    {
      foreach ($this->myConfig['table_columns'][$theTableName] as $name => $type)
      {
        if ($type!='timestamp')
        {
          $columns[] = ['name' => $name, 'type' => $type.' DEFAULT NULL'];
        }
        else
        {
          $columns[] = ['name' => $name, 'type' => $type.' NULL'];
        }
      }
    }

    return $columns;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Compares the tables listed in the config file and the tables found in the audit schema
   */
  public function compareAuditTables()
  {
    foreach ($this->myConfig['tables'] as $table_name => $flag)
    {
      if ($flag)
      {
        $miss_table = DataLayer::searchInRowSet('table_name', $table_name, $this->myAuditSchemaTables);
        if ($miss_table)
        {
          // @todo comparing the audit and data table
        }
        else
        {
          // @todo comparing the audit and data table
        }
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Compares the tables listed in the config file and the tables found in the data schema
   */
  public function compareTables()
  {
    foreach ($this->myDataSchemaTables as $table)
    {
      if (isset($this->myConfig['tables'][$table['table_name']]))
      {
        if (!$this->myConfig['tables'][$table['table_name']])
        {
          $this->logInfo("Audit flag is not set in table {$table['table_name']}.\n");
        }
      }
      else
      {
        $this->logInfo("Find new table {$table['table_name']}, not listed in config file.\n");
        $this->myConfig['tables'][$table['table_name']] = false;
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Getting list of all tables from information_schema of database from config file.
   */
  public function listOfTables()
  {
    $this->myDataSchemaTables = DataLayer::getTablesNames($this->myConfig['database']['data_schema']);

    $this->myAuditSchemaTables = DataLayer::getTablesNames($this->myConfig['database']['audit_schema']);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Getting columns names and data_types from table from information_schema of database from config file.
   *
   * @param string $theTableName Name of table
   *
   * @return array
   */
  public function columnsOfTable($theTableName)
  {
    $result = DataLayer::getTableColumns($this->myConfig['database']['data_schema'], $theTableName);

    return $result;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Reads configuration parameters from the configuration file.
   *
   * @param string $theConfigFilename
   *
   * @throws \SetBased\Audit\Exception\RuntimeException
   */
  public function readConfigFile($theConfigFilename)
  {
    $content = file_get_contents($theConfigFilename);
    if ($content===false)
    {
      throw new RuntimeException("Unable to read file '%s'.", $theConfigFilename);
    }
    $this->myConfig = json_decode($content, true);

    foreach ($this->myConfig['tables'] as $table_name => $flag)
    {
      $this->myConfig['tables'][$table_name] = filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Write new data to config file.
   */
  private function rewriteConfig()
  {
    Util::writeTwoPhases($this->myConfigFileName, json_encode($this->myConfig, JSON_PRETTY_PRINT));
  }

  //--------------------------------------------------------------------------------------------------------------------

}

//----------------------------------------------------------------------------------------------------------------------
