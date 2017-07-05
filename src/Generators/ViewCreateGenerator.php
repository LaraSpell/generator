<?php

namespace LaraSpell\Generators;

use LaraSpell\Stub;
use LaraSpell\Traits\TableDataGetter;

class ViewCreateGenerator extends ViewGenerator
{
    use TableDataGetter;

    protected function getTableSchema()
    {
        return $this->tableSchema;
    }

    public function getData()
    {
        $data = parent::getData();
        $data['page_title'] = 'Create '.$this->tableSchema->getLabel();
        $data['form'] = [
            'table' => $this->tableSchema->getName(),
            'table_singular' => $this->tableSchema->getSingularName(),
            'id' => $this->getFormId(),
            'attributes' => $this->getFormAttributes(),
            'fields' => $this->getFormFields(),
        ];

        return $data;
    }

    protected function getFormId()
    {
        return "form-create-".$this->tableSchema->getSingularName();
    }

    protected function getActionUrl()
    {
        $routeName = $this->tableSchema->getRouteCreateName();
        return "{{ route('{$routeName}') }}";
    }

    protected function getFormAttributes()
    {
        $routeName = $this->getActionUrl();
        $upload = count(array_filter($this->tableSchema->getFields(), function($field) {
            return $field->isInputFile();
        })) > 0;

        $attributes = [];
        $attributes['id'] = $this->getFormId();
        $attributes['method'] = "POST";
        $attributes['action'] = $this->getActionUrl();
        if ($upload) {
            $attributes['enctype'] = "multipart/form-data";
        }

        $attrs = [];
        foreach($attributes as $key => $value) {
            $attrs[] = "{$key}=\"{$value}\"";
        }
        return implode(" ", $attrs);
    }

    protected function getFormFields()
    {
        $schema = $this->tableSchema;
        $rootSchema = $schema->getRootSchema();
        $inputableFields = $schema->getInputableFields();
        $includeFields = [];
        foreach($inputableFields as $field) {
            $params = $field->getInputParams();
            $key = $field->getColumnName();
            $params['value'] = "eval(\"old('{$key}')\")";
            $view = $rootSchema->getView($field->getInputView());
            $includeFields[] = "@include('{$view}', ".$this->phpify($params, true).")";
        }

        $code = $this->makeCodeGenerator();
        $code->addStatements(implode("\n\n", $includeFields));

        return $code->generateCode();
    }

    protected function makeCodeGenerator()
    {
        $code = new CodeGenerator;
        $code->setIndent("  "); // 2 spaces
        return $code;
    }

}