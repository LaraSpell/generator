<?php

namespace LaraSpell\Generators;

use LaraSpell\Stub;
use LaraSpell\Traits\TableDataGetter;

class ViewListGenerator extends ViewGenerator
{

    use TableDataGetter;

    protected function getTableSchema()
    {
        return $this->tableSchema;
    }

    public function getData()
    {
        $data = parent::getData();
        $data['page_title'] = 'List '.$this->tableSchema->getLabel();
        $data['route_create'] = $this->tableSchema->getRouteCreateName();
        $data['table'] = [
            'label' => $this->tableSchema->getLabel(),
            'id' => $this->getTableId(),
            'html' => $this->generateHtmlTable(),
            'pagination' => $this->generateHtmlPagination(),
        ];

        return $data;
    }

    protected function getTableId()
    {
        return "table-".$this->tableSchema->getName();
    }

    protected function generateHtmlTable()
    {
        $tableId = $this->getTableId();
        $tableData = $this->getTableData();
        $inputableFields = $this->tableSchema->getInputableFields();
        $theads = [];
        $bodys = [];
        foreach($inputableFields as $field) {
            $col = $field->getColumnName();
            $tableCode = $field->getTableCode() ?: '{{ ${? varname ?}[\'{? column ?}\'] }}';
            $label = $field->getLabel();
            $relation = $field->getRelation();
            if ($relation AND $relation['col_alias']) {
                $col = $relation['col_alias'];
            }
            $stub = new Stub($tableCode);
            $tableCode = $stub->render([
                'field' => $field->toArray(),
                'disk' => $field->getUploadDisk(),
                'column' => $col,
                'varname' => $tableData->model_varname
            ]);

            $theads[] = "<th class='column-{$col}'>{$label}</th>";
            $tbodys[] = "<td class='column-{$col}'>{$tableCode}</td>";
        }

        $code = $this->makeCodeGenerator();
        $code->addStatements('
            <table id="'.$tableId.'" class="table table-bordered table-striped table-hover">
                <thead>
                    <tr>
                        <th width="20" class="text-center column-number">No</th>
                        '.implode("\n", $theads).'
                        <th class="text-center column-action">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pagination[\'items\'] as $i => $'.$tableData->model_varname.')
                    <tr>
                        <td class="text-center column-number">{{ $pagination[\'from\'] + $i }}</td>
                        '.implode("\n", $tbodys).'
                        <td width="200" class="text-center column-action">
                            <a class="btn btn-sm btn-edit btn-default" href="{{ route(\''.$tableData->route->page_detail.'\', [$'.$tableData->model_varname.'[\''.$tableData->primary_key.'\']]) }}">Show</a>
                            <a class="btn btn-sm btn-edit btn-primary" href="{{ route(\''.$tableData->route->form_edit.'\', [$'.$tableData->model_varname.'[\''.$tableData->primary_key.'\']]) }}">Edit</a>
                            <a class="btn btn-sm btn-delete btn-danger" href="{{ route(\''.$tableData->route->delete.'\', [$'.$tableData->model_varname.'[\''.$tableData->primary_key.'\']]) }}">Delete</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        ');

        return $code->generateCode();
    }

    protected function generateHtmlPagination()
    {
        $code = $this->makeCodeGenerator();
        $code->addStatements('
            <ul class="pagination">
                @foreach($pagination[\'links\'] as $link)
                <li class="{{ $link[\'page\'] == $pagination[\'page\']? \'active\' : \'\' }}">
                    <a href="{{ $link[\'url\'] }}">{{ $link[\'label\'] }}</a>
                </li>
                @endforeach
            </ul>
        ');

        return $code->generateCode();
    }

    protected function makeCodeGenerator()
    {
        $code = new CodeGenerator;
        $code->setIndent("  "); // 2 spaces
        return $code;
    }

}