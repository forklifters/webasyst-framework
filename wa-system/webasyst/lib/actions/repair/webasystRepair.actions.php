<?php

class webasystRepairActions extends waActions
{
    protected function preExecute()
    {
        if (!wa()->getUser()->isAdmin()) {
            throw new waRightsException('Access denied');
        }
    }

    /**
     * @throws ReflectionException
     */
    public function defaultAction()
    {
        $actions = array();
        $reflection = new ReflectionClass($this);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            if (true
                && ($method->getDeclaringClass()->getName() == __CLASS__)
                && preg_match('@^(\w+)Action$@', $method->getName(), $matches)
            ) {
                $name = $matches[1];
                if ($name !== 'default') {
                    if ($comment = $method->getDocComment()) {
                        $description = preg_replace('_(^|[\r\n]+)[ \t]*(/\*+|\*+\/|\*+)_m', '', $comment);
                    } else {
                        $description = $name;
                    }
                    $actions[$name] = $description;
                }
            }
        }
        $this->display(compact('actions'));
    }

    /**
     * Database structure repair helper
     */
    public function databaseAction()
    {
        $sync = (array)waRequest::post('sync');

        $run_sql = !!waRequest::post('run');

        foreach (wa()->getApps(true) as $app_id => &$app_info) {
            /** @var waAppConfig $config */
            $config = wa($app_id)->getConfig();
            $path = $config->getAppConfigPath('db');

            $tables = $this->getDefaultTablesSchema($path);

            $info = $this->compareTables($tables, $sync, $run_sql);
            if ($info) {
                $info['type'] = 'app';
                $apps[$app_id] = $app_info + $info;
            }

            if (!empty($app_info['plugins'])) {
                foreach ($config->getPlugins() as $plugin_id => $plugin_info) {
                    $path = wa()->getConfig()->getAppsPath($app_id, 'plugins/'.$plugin_id.'/lib/config/db.php');
                    $tables = $this->getDefaultTablesSchema($path);
                    $info = $this->compareTables($tables, $sync, $run_sql);
                    if ($info) {
                        $info['type'] = 'widget';
                        $apps[$app_id.'/'.$plugin_id] = $plugin_info + $info;
                    }
                }
            }
        }

        if ($run_sql) {
            wa()->getConfig()->clearCache();
        }

        $this->display(compact('apps', 'sync', 'run_sql'));
    }

    private function databaseGetModel()
    {
        static $m;
        if (empty($m)) {
            $m = new waModel();
        }
        return $m;
    }

    private function workupDefaultTableSchema(&$schema)
    {
        unset($schema[':keys']);
        unset($schema[':options']);


        foreach ($schema as $column => &$info) {
            if (strpos($column, ':') !== 0) {
                $info['type'] = ifset($info, 0, 'n/a');
                unset($info[0]);
                $info['params'] = ifset($info, 1, null);
                unset($info[1]);
                switch ($info['type']) {
                    case 'enum':
                        if (strpos($info['params'], '"') === 0) {
                            $info['params'] = str_replace('"', "'", $info['params']);
                        }
                        $info['params'] = preg_replace("@',\s+'@", "','", $info['params']);
                        break;
                }
                $info += array(
                    'autoincrement' => false,
                    'default'       => null,
                    'null'          => 1,
                    'charset'       => null,
                    'collation'     => null,
                );
                $info['status'] = array();
            }
            unset($properties);
        }
    }

    private function getDefaultTablesSchema($path)
    {
        if (file_exists($path)) {
            $default_schema = include($path);

            foreach ($default_schema as $table => &$default_columns) {
                $this->workupDefaultTableSchema($default_columns);
                unset($default_columns);
            }
        } else {
            $default_schema = array();
        }
        return $default_schema;
    }

    private function getCurrentTableSchema($table)
    {
        $config = waSystem::getInstance()->getConfig()->getDatabase();

        $database = $config['default']['database'];

        $sql = <<<SQL
SELECT
  `COLUMN_NAME` 'column',
  `CHARACTER_SET_NAME` 'charset',
  `COLLATION_NAME` 'collation'
FROM information_schema.COLUMNS
WHERE
    TABLE_SCHEMA = '{$database}'
    AND
    `TABLE_NAME` = '{$table}'
SQL;

        $m = $this->databaseGetModel();

        try {
            $schema = $m->describe($table);
            $encoding = $m->query($sql)->fetchAll('column');
            foreach ($encoding as $column => $column_encoding) {
                if (isset($schema[$column])) {
                    $schema[$column] += $column_encoding;
                }
            }
        } catch (waDbException $ex) {
            $schema = array();
        }
        foreach ($schema as $column => &$current_column) {
            if (strpos($column, ':') !== 0) {
                $current_column += array(
                    'autoincrement' => false,
                    'default'       => null,
                    'null'          => 1,
                    'params'        => null,
                    'charset'       => null,
                    'collation'     => null,
                );
            }
            unset($current_column);

        }
        return $schema;
    }

    private function compareTables($default_tables, $sync_columns, $run_sql)
    {
        $m = $this->databaseGetModel();

        $check_fields = array(
            'null'    => 'Nullable differ, %s expected',
            'default' => 'Default value differ, %s expected',
            'type'    => 'Type mismatch, %s expected',
            'params'  => 'Type params mismatch, %s expected',
        );


        $errors = 0;
        $sql = array();

        $current_tables = array();

        foreach ($default_tables as $table => &$default_columns) {

            $table_errors = false;

            $current_tables[$table] = $this->getCurrentTableSchema($table);

            foreach ($default_columns as $column => &$default_column) {
                if (strpos($column, ':') !== 0) {
                    $sync_column = array();
                    if (isset($current_tables[$table][$column])) {

                        foreach ($check_fields as $check_field => $check_message) {
                            $default_value = $default_column[$check_field];
                            $current_value = $current_tables[$table][$column][$check_field];
                            $strict = in_array(null, array($default_value, $current_value), true);
                            if ($strict ? ($default_value !== $current_value) : ($default_value != $current_value)) {

                                $default_column['status'][$check_field] = sprintf(
                                    $check_message,
                                    var_export($default_column[$check_field], true)
                                );

                                if (!empty($sync_columns[$table][$column][$check_field])) {
                                    $sync_column[$check_field] = $default_column[$check_field];
                                }
                            }
                        }
                    } else {
                        $current_tables[$table][$column] = $default_column;
                        $default_column['status']['name'] = 'Missed field';
                    }

                    if (!empty($sync_columns[$table][$column])) {
                        $sync_column += $current_tables[$table][$column];

                        if (isset($sync_column['default']) && ($sync_column['default'] === 'NULL')) {
                            $sync_column['default'] = null;
                        }

                        $db_schema = array(
                            $table => array(
                                $column => $sync_column,
                                ':keys' => array(),
                            ),
                        );

                        try {
                            $sql[$table][$column] = $m->modifyColumn($column, $db_schema, null, $table, !$run_sql).';';
                            if ($run_sql) {
                                $sql[$table][$column] .= "\n#\tOK";
                            }
                        } catch (waDbException $ex) {
                            $sql[$table][$column] = '#ERROR: '.$ex->getMessage();
                        }
                    }

                    if ($default_column['status']) {
                        $table_errors = true;
                        ++$errors;
                    } else {
                        unset($default_columns[$column]);
                    }
                }

                unset($default_column);
            }
            unset($info);
            if (empty($table_errors)) {
                unset($default_tables[$table]);
            }
        }


        if (!empty($errors)) {
            return compact('errors', 'sql', 'current_tables', 'default_tables');
        } else {
            return false;
        }
    }

    /**
     * Widgets order repair
     */
    public function widgetsAction()
    {
        $contact_id = $this->getUserId();
        $widget_model = new waWidgetModel();
        $rows = $widget_model->getByContact($contact_id);

        $data = array();
        foreach ($rows as $row) {
            $data[$row['block']][] = $row;
        }

        $w = $b = 0;

        $real_block = 0;
        foreach ($data as $block => $block_data) {
            if ($real_block != $block) {
                $b++;
                $widget_model->updateByField(array(
                    'contact_id'   => $contact_id,
                    'dashboard_id' => null,
                    'block'        => $block,
                ), array('block' => $real_block));
            }
            foreach ($block_data as $sort => $row) {
                if ($row['sort'] != $sort) {
                    $widget_model->updateById($row['id'], array('sort' => $sort));
                    $w++;
                }
            }
            $real_block++;
        }

        echo 'OK';
        if ($b) {
            echo "\t".$b.' block(s) has been fixed.'.PHP_EOL;
        }
        if ($w) {
            echo "\t".$w.' widgets(s) has been fixed.'.PHP_EOL;
        }
    }
}
