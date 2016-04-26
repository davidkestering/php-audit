<?php
//----------------------------------------------------------------------------------------------------------------------
namespace SetBased\Audit;

use SetBased\Audit\MySql\DataLayer;
use SetBased\Stratum\Style\StratumStyle;

//--------------------------------------------------------------------------------------------------------------------
/**
 * Class for metadata of tables.
 */
class Table
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The unique alias for this data table.
   *
   * @var string
   */
  private $alias;

  /**
   * The metadata (additional) audit columns (as stored in the config file).
   *
   * @var Columns
   */
  private $auditColumns;

  /**
   * The name of the schema with the audit tables.
   *
   * @var string
   */
  private $auditSchemaName;

  /**
   * The name of the schema with the data tables.
   *
   * @var string
   */
  private $dataSchemaName;

  /**
   * The metadata of the columns of the data table as stored in the config file.
   *
   * @var Columns
   */
  private $dataTableColumnsConfig;

  /**
   * The metadata of the columns of the data table retrieved from information_schema.
   *
   * @var Columns
   */
  private $dataTableColumnsDatabase;

  /**
   * The output decorator
   *
   * @var StratumStyle
   */
  private $io;

  /**
   * The skip variable for triggers.
   *
   * @var string
   */
  private $skipVariable;

  /**
   * The name of this data table.
   *
   * @var string
   */
  private $tableName;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Object constructor.
   *
   * @param StratumStyle $io                       The output for log messages.
   * @param string       $theTableName             The table name.
   * @param string       $theDataSchema            The name of the schema with data tables.
   * @param string       $theAuditSchema           The name of the schema with audit tables.
   * @param array[]      $theConfigColumnsMetadata The columns of the data table as stored in the config file.
   * @param array[]      $theAuditColumnsMetadata  The columns of the audit table as stored in the config file.
   * @param string       $theAlias                 An unique alias for this table.
   * @param string       $theSkipVariable          The skip variable
   */
  public function __construct($io,
                              $theTableName,
                              $theDataSchema,
                              $theAuditSchema,
                              $theConfigColumnsMetadata,
                              $theAuditColumnsMetadata,
                              $theAlias,
                              $theSkipVariable)
  {
    $this->io                       = $io;
    $this->tableName                = $theTableName;
    $this->dataTableColumnsConfig   = new Columns($theConfigColumnsMetadata);
    $this->dataSchemaName           = $theDataSchema;
    $this->auditSchemaName          = $theAuditSchema;
    $this->dataTableColumnsDatabase = new Columns($this->getColumnsFromInformationSchema());
    $this->auditColumns             = new Columns($theAuditColumnsMetadata);
    $this->alias                    = $theAlias;
    $this->skipVariable             = $theSkipVariable;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns a random alias for this table.
   *
   * @return string
   */
  public static function getRandomAlias()
  {
    return uniqid();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Creates missing audit table for this table.
   */
  public function createMissingAuditTable()
  {
    $this->io->logInfo('Creating audit table <dbo>%s.%s<dbo>', $this->auditSchemaName, $this->tableName);

    $columns = Columns::combine($this->auditColumns, $this->dataTableColumnsDatabase);
    DataLayer::createAuditTable($this->auditSchemaName, $this->tableName, $columns->getColumns());
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Creates audit triggers on this table.
   *
   * @param string[] $additionalSql Additional SQL statements to be include in triggers.
   */
  public function createTriggers($additionalSql)
  {
    // Lock the table to prevent insert, updates, or deletes between dropping and creating triggers.
    $this->lockTable($this->tableName);

    // Drop all triggers, if any.
    $this->dropTriggers();

    // Create or recreate the audit triggers.
    $this->createTableTrigger('INSERT', $this->skipVariable, $additionalSql);
    $this->createTableTrigger('UPDATE', $this->skipVariable, $additionalSql);
    $this->createTableTrigger('DELETE', $this->skipVariable, $additionalSql);

    // Insert, updates, and deletes are no audited again. So, release lock on the table.
    $this->unlockTables();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the name of this table.
   *
   * @return string
   */
  public function getTableName()
  {
    return $this->tableName;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Main function for work with table.
   *
   * @param string[] $additionalSql Additional SQL statements to be include in triggers.
   *
   * @return \array[] Columns for config file
   */
  public function main($additionalSql)
  {
    $comparedColumns = null;
    if (isset($this->dataTableColumnsConfig))
    {
      $comparedColumns = $this->getTableColumnInfo();
    }

    if (empty($comparedColumns['new_columns']) && empty($comparedColumns['obsolete_columns']))
    {
      if (empty($comparedColumns['altered_columns']))
      {
        $this->createTriggers($additionalSql);
      }
    }

    return $comparedColumns;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Adds new columns to audit table.
   *
   * @param array[] $theColumns Columns array
   */
  private function addNewColumns($theColumns)
  {
    DataLayer::addNewColumns($this->auditSchemaName, $this->tableName, $theColumns);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Creates a triggers for this table.
   *
   * @param string      $theAction   The trigger action (INSERT, DELETE, or UPDATE).
   * @param string|null $skipVariable
   * @param string[]    $additionSql The additional SQL statements to be included in triggers.
   */
  private function createTableTrigger($theAction, $skipVariable, $additionSql)
  {
    $triggerName = $this->getTriggerName($theAction);

    $this->io->logVerbose('Creating trigger <dbo>%s.%s</dbo> on table <dbo>%s.%s</dbo>',
                          $this->dataSchemaName,
                          $triggerName,
                          $this->dataSchemaName,
                          $this->tableName);

    DataLayer::createAuditTrigger($this->dataSchemaName,
                                  $this->auditSchemaName,
                                  $this->tableName,
                                  $triggerName,
                                  $theAction,
                                  $this->auditColumns,
                                  $this->dataTableColumnsDatabase,
                                  $skipVariable,
                                  $additionSql);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Drops all triggers from this table.
   */
  private function dropTriggers()
  {
    $triggers = DataLayer::getTableTriggers($this->dataSchemaName, $this->tableName);
    foreach ($triggers as $trigger)
    {
      $this->io->logVerbose('Dropping trigger <dbo>%s</dbo> on <dbo>%s.%s</dbo>',
                            $trigger['trigger_name'],
                            $this->dataSchemaName,
                            $this->tableName);

      DataLayer::dropTrigger($this->dataSchemaName, $trigger['trigger_name']);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Compares columns types from table in data_schema with columns in config file.
   *
   * @return array[]
   */
  private function getAlteredColumns()
  {
    $alteredColumnsTypes = Columns::differentColumnTypes($this->dataTableColumnsDatabase,
                                                         $this->dataTableColumnsConfig);
    foreach ($alteredColumnsTypes as $column)
    {
      $this->io->logInfo('Type of <dbo>%s.%s</dbo> has been altered to <dbo>%s</dbo>',
                         $this->tableName,
                         $column['column_name'],
                         $column['column_type']);
    }

    return $alteredColumnsTypes;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Selects and returns the metadata of the columns of this table from information_schema.
   *
   * @return array[]
   */
  private function getColumnsFromInformationSchema()
  {
    $result = DataLayer::getTableColumns($this->dataSchemaName, $this->tableName);

    return $result;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Compare columns from table in data_schema with columns in config file.
   *
   * @return array[]
   */
  private function getTableColumnInfo()
  {
    $columnActual  = new Columns(DataLayer::getTableColumns($this->auditSchemaName, $this->tableName));
    $columnsConfig = Columns::combine($this->auditColumns, $this->dataTableColumnsConfig);
    $columnsTarget = Columns::combine($this->auditColumns, $this->dataTableColumnsDatabase);

    $newColumns      = Columns::notInOtherSet($columnsTarget, $columnActual);
    $obsoleteColumns = Columns::notInOtherSet($columnsConfig, $columnsTarget);

    $this->loggingColumnInfo($newColumns, $obsoleteColumns);
    $this->addNewColumns($newColumns);

    return ['full_columns'     => $this->getTableColumnsFromConfig($newColumns, $obsoleteColumns),
            'new_columns'      => $newColumns,
            'obsolete_columns' => $obsoleteColumns,
            'altered_columns'  => $this->getAlteredColumns()];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Check for know what columns array returns.
   *
   * @param array[] $theNewColumns
   * @param array[] $theObsoleteColumns
   *
   * @return Columns
   */
  private function getTableColumnsFromConfig($theNewColumns, $theObsoleteColumns)
  {
    if (!empty($theNewColumns) && !empty($theObsoleteColumns))
    {
      return $this->dataTableColumnsConfig;
    }

    return $this->dataTableColumnsDatabase;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Create and return trigger name.
   *
   * @param string $theAction Trigger on action (Insert, Update, Delete)
   *
   * @return string
   */
  private function getTriggerName($theAction)
  {
    return strtolower(sprintf('trg_%s_%s', $this->alias, $theAction));
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Lock the table to prevent insert, updates, or deletes between dropping and creating triggers.
   *
   * @param string $theTableName Name of table
   */
  private function lockTable($theTableName)
  {
    DataLayer::lockTable($theTableName);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Logging new and obsolete columns.
   *
   * @param array[] $theNewColumns
   * @param array[] $theObsoleteColumns
   */
  private function loggingColumnInfo($theNewColumns, $theObsoleteColumns)
  {
    if (!empty($theNewColumns) && !empty($theObsoleteColumns))
    {
      $this->io->logInfo('Found both new and obsolete columns for table %s', $this->tableName);
      $this->io->logInfo('No action taken');
      foreach ($theNewColumns as $column)
      {
        $this->io->logInfo('New column %s', $column['column_name']);
      }
      foreach ($theObsoleteColumns as $column)
      {
        $this->io->logInfo('Obsolete column %s', $column['column_name']);
      }
    }

    foreach ($theObsoleteColumns as $column)
    {
      $this->io->logInfo('Obsolete column %s.%s', $this->tableName, $column['column_name']);
    }

    foreach ($theNewColumns as $column)
    {
      $this->io->logInfo('New column %s.%s', $this->tableName, $column['column_name']);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Releases all table locks.
   */
  private function unlockTables()
  {
    DataLayer::unlockTables();
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
