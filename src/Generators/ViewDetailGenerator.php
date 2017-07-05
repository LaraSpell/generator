<?php

namespace LaraSpell\Generators;

use LaraSpell\Stub;
use LaraSpell\Traits\TableDataGetter;

class ViewDetailGenerator extends ViewGenerator
{

    use TableDataGetter;

    protected function getTableSchema()
    {
        return $this->tableSchema;
    }

    public function getData()
    {
        $data = parent::getData();
        $tableData = $this->getTableData();
        $data['page_title'] = 'Detail '.$this->tableSchema->getLabel();
        $data['varname'] = $tableData->model_varname;
        $data['primary_key'] = $tableData->primary_key;
        $data['route_list'] = $tableData->route->page_list;
        $data['route_edit'] = $tableData->route->form_edit;
        $data['fields'] = $this->generateFields();
        return $data;
    }

    protected function generateFields()
    {
        $tableData = $this->getTableData();
        $fields = $this->tableSchema->getFields();
        $code = $this->makeCodeGenerator();
        foreach($fields as $field) {
            $column = $field->getColumnName();
            $relation = $field->getRelation();
            if ($relation AND $relation['col_alias']) {
                $column = $relation['col_alias'];
            }
            $stub = new Stub($field->getReadCode());
            $code->addStatements("<!-- Column ".$field->getColumnName()." -->");
            $code->addStatements($stub->render([
                'label' => $field->getLabel(),
                'field' => $field->toArray(),
                'varname' => $tableData->model_varname,
                'column' => $column,
                'disk' => $field->getUploadDisk(),
            ]));
        }
        return $code->generateCode();
    }

    protected function makeCodeGenerator()
    {
        $code = new CodeGenerator;
        $code->setIndent("  "); // 2 spaces
        return $code;
    }

}