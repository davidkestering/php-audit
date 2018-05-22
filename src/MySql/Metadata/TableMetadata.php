<?php

namespace SetBased\Audit\MySql\Metadata;

/**
 * Class for the metadata of a database table.
 */
class TableMetadata
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The properties of the table that are stored by this class.
   *
   * var string[]
   */
  private static $fields = ['table_schema',
                            'table_name',
                            'engine',
                            'character_set_name',
                            'table_collation'];

  /**
   * The metadata of the columns of this table.
   *
   * @var TableColumnsMetadata.
   */
  private $columns;

  /**
   * The the properties of this table column.
   *
   * @var array
   */
  private $properties = [];

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Object constructor.
   *
   * @param array[] $properties The metadata of the table.
   * @param array[] $columns    The metadata of the columns of this table.
   */
  public function __construct($properties, $columns)
  {
    foreach (static::$fields as $field)
    {
      if (isset($properties[$field]))
      {
        $this->properties[$field] = $properties[$field];
      }
    }

    $this->columns = new TableColumnsMetadata($columns);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Compares two the metadata of two tables. Returns an array with the names of the different properties.
   *
   * @param TableMetadata $table1 The metadata of the first table.
   * @param TableMetadata $table2 The metadata of the second table.
   *
   * @return string[]
   */
  public static function compareOptions($table1, $table2)
  {
    $diff = [];

    foreach (self::$fields as $field)
    {
      if (!in_array($field, ['table_schema', 'table_name']))
      {
        if ($table1->getProperty($field)!=$table2->getProperty($field))
        {
          $diff[] = $field;
        }
      }
    }

    return $diff;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns table columns.
   *
   * @return TableColumnsMetadata
   */
  public function getColumns()
  {
    return $this->columns;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns a property of this table.
   *
   * @param string $name The name of the property.
   *
   * @return string|null
   */
  public function getProperty($name)
  {
    if (isset($this->properties[$name]))
    {
      return $this->properties[$name];
    }

    return null;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the name of schema.
   *
   * @return string
   */
  public function getSchemaName()
  {
    return $this->properties['table_schema'];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the name of this table.
   *
   * @return string
   */
  public function getTableName()
  {
    return $this->properties['table_name'];
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
