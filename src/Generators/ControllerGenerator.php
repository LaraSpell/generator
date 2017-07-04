<?php

namespace LaraSpell\Generators;

use LaraSpell\Schema\Table;
use LaraSpell\Stub;
use LaraSpell\Traits\TableDataGetter;

class ControllerGenerator extends ClassGenerator
{
    use TableDataGetter;

    const CLASS_REQUEST = 'Illuminate\Http\Request';
    const CLASS_RESPONSE = 'Illuminate\Http\Response';

    protected $tableSchema;

    public function __construct(Table $tableSchema)
    {
        parent::__construct($tableSchema->getControllerClass());
        $this->tableSchema = $tableSchema;
        $this->initClass();
        $this->addMethodsFromReflection();
    }

    protected function getTableSchema()
    {
        return $this->tableSchema;
    }

    protected function initClass()
    {
        $repositories = $this->getRequiredRepositories();
        $this->setParentClass('Controller');
        $this->useClass(static::CLASS_REQUEST);
        foreach($repositories as $varName => $repository) {
            $label = ucfirst(snake_case($varName, ' '));
            $this->addProperty($varName, $repository, 'protected', null, $label.' repository');
        }
        $this->setDocblock(function($docblock) {
            $authorName = $this->tableSchema->getRootSchema()->getAuthorName();
            $authorEmail = $this->tableSchema->getRootSchema()->getAuthorEmail();
            $docblock->addText("Generated by LaraSpell");
            $docblock->addAnnotation("author", "{$authorName} <{$authorEmail}>");
            $docblock->addAnnotation("created", date('r'));
        });
    }

    protected function methodConstruct(MethodGenerator $method)
    {
        $repositories = $this->getRequiredRepositories();
        $method->setDocblock(function($docblock) use ($repositories) {
            $docblock->addText("Constructor");
            foreach($repositories as $varName => $repository) {
                $docblock->addParam($varName, $repository);
            }
        });
        foreach($repositories as $varName => $repository) {
            $method->addArgument($varName, $repository);
        }
        $method->setCode(function($code) use ($repositories) {
            foreach($repositories as $varName => $repository) {
                $code->addStatements("\$this->{$varName} = \${$varName};");
            }
        });
    }

    protected function methodPageList(MethodGenerator $method)
    {
        $data = $this->getTableData();
        $method->addArgument('request', static::CLASS_REQUEST);
        $method->setDocblock(function($docblock) use ($data) {
            $docblock->addText("Display list {$data->table_name}");
            $docblock->addParam('request', static::CLASS_REQUEST);
            $docblock->setReturn(static::CLASS_RESPONSE);
        });
        $joins = [];
        $inputableFieldsHasRelation = $this->getInputableFieldsHasRelation();
        foreach($inputableFieldsHasRelation as $field) {
            $relation = $field->getRelation();
            $relatedTable = $this->tableSchema->getRootSchema()->getTable($relation['table']);
            $tableVarname = $relatedTable->getSingularName();
            $colLabel = $relation['col_label'];
            $colLabelAlias = $relation['col_alias'];

            $joins[] = [
                'table' => $relation['table'],
                'type' => 'inner',
                'key_from' => $relation['key_from'],
                'key_to' => $relation['key_to'],
                'selects' => [
                    $colLabelAlias? $colLabel.' as '.$colLabelAlias : $colLabel
                ],
            ];
        }

        $method->setCode(function($code) use ($data, $joins) {
            $paginationOptions = [
                'keyword' => 'eval("$keyword")'
            ];
            if (!empty($joins)) {
                $paginationOptions['joins'] = $joins;
            }

            $code->addStatements("
                \$page = (int) \$request->get('page') ?: 1;
                \$limit = (int) \$request->get('limit') ?: 10;
                \$keyword = \$request->get('keyword');

                \$data['title'] = 'List {$data->label}';
                \$data['pagination'] = \$this->{$data->repository->varname}->getPagination(\$page, \$limit, ".$this->phpify($paginationOptions, true).");

                return view('{$data->view->page_list}', \$data);
            ");
        });
    }

    protected function methodPageDetail(MethodGenerator $method)
    {
        $data = $this->getTableData();
        $method->addArgument('request', static::CLASS_REQUEST);
        $method->addArgument($data->primary_varname);
        $method->setDocblock(function($docblock) use ($data) {
            $docblock->addText("Show detail {$data->model_varname}");
            $docblock->addParam('request', static::CLASS_REQUEST);
            $docblock->addParam($data->primary_varname, 'string');
            $docblock->setReturn(static::CLASS_RESPONSE);
        });

        $initModelCode = $this->getInitModelCode();
        $method->setCode(function($code) use ($initModelCode, $data) {
            $code->addStatements($initModelCode);
            $code->ln();
            $view = $data->view->page_detail;
            $code->addStatements("\$data['title'] = 'Detail {$data->label}';");
            $code->addStatements("\$data['{$data->model_varname}'] = \${$data->model_varname};");
            $code->ln();
            $code->addStatements("return view('{$view}', \$data);");
        });
    }

    protected function methodFormCreate(MethodGenerator $method)
    {
        $fieldsHasRelation = $this->getInputableFieldsHasRelation();
        $data = $this->getTableData();
        $method->addArgument('request', static::CLASS_REQUEST);
        $method->setDocblock(function($docblock) use ($data) {
            $docblock->addText("Display form create {$data->model_varname}");
            $docblock->addParam('request', static::CLASS_REQUEST);
            $docblock->setReturn(static::CLASS_RESPONSE);
        });
        $method->setCode(function($code) use ($data, $fieldsHasRelation) {
            $code->addStatements("\$data['title'] = 'Form Create {$data->label}';");
            foreach($fieldsHasRelation as $field) {
                $relation = $field->getRelation();
                $varName = $relation['var_name'];
                $colValue = $relation['col_value'];
                $colLabel = $relation['col_label'];
                $selectedColumns = $this->phpify([$colValue, $colLabel]);
                $relatedTable = $this->tableSchema->getRootSchema()->getTable($relation['table']);
                $relatedTableName = $relatedTable->getName();
                $listVarname = camel_case($relatedTableName);
                $repository = $this->getRepositoryPropertyName($relatedTable);
                $code->ln();
                $code->addStatements("
                    // Set {$varName}
                    \${$listVarname} = \$this->{$repository}->all(".$selectedColumns.");
                    \$data['{$varName}'] = array_map(function(\$record) {
                        return [
                            'value' => \$record['{$colValue}'],
                            'label' => \$record['{$colLabel}']
                        ];
                    }, \${$listVarname});
                ");
            }
            $code->ln();
            $code->addStatements("return view('{$data->view->form_create}', \$data);");
        });
    }

    protected function methodPostCreate(MethodGenerator $method)
    {
        $data = $this->getTableData();
        $method->addArgument('request', $data->request->class_create_with_namespace);
        $method->setDocblock(function($docblock) use ($data) {
            $docblock->addText("Insert new {$data->model_varname}");
            $docblock->addParam('request', $data->request->class_create_with_namespace);
            $docblock->setReturn(static::CLASS_RESPONSE);
        });

        $method->setCode(function($code) use ($data) {
            $inputFiles = $this->tableSchema->getInputFileFields();
            $code->addStatements("
                \$data = \$this->resolveFormInputs(\$request->all());
            ");
            $code->ln();

            foreach($inputFiles as $field) {
                $col = $field->getColumnName();
                $varName = camel_case($col);
                $path = $field->getUploadPath();
                $disk = $field->getUploadDisk();
                $code->addStatements("
                    // Uploading {$col}
                    \${$varName} = \$request->file('{$col}');
                    if (\${$varName}) {
                        \$filename = \${$varName}->getClientOriginalName();
                        \$path = '{$path}';
                        \$data['{$col}'] = \${$varName}->storeAs(\$path, \$filename, '{$disk}');
                    }
                ");
                $code->ln();
            }

            $code->addStatements("
                // Insert data
                \${$data->model_varname} = \$this->{$data->repository->varname}->create(\$data);
                if (!\${$data->model_varname}) {
                    \$message = 'Something went wrong when create {$data->label}';
                    return back()->with('danger', \$message);
                }

                \$message = '{$data->label} has been created!';
                return redirect()->route('{$data->route->page_list}')->with('info', \$message);
            ");
        });
    }

    protected function methodFormEdit(MethodGenerator $method)
    {
        $fieldsHasRelation = $this->getInputableFieldsHasRelation();
        $data = $this->getTableData();
        $method->addArgument('request', static::CLASS_REQUEST);
        $method->addArgument($data->primary_varname);
        $method->setDocblock(function($docblock) use ($data) {
            $docblock->addText("Display form edit {$data->model_varname}");
            $docblock->addParam('request', static::CLASS_REQUEST);
            $docblock->addParam($data->primary_varname, 'string');
            $docblock->setReturn(static::CLASS_RESPONSE);
        });

        $initModelCode = $this->getInitModelCode();
        $method->setCode(function($code) use ($initModelCode, $data, $fieldsHasRelation) {
            $code->addStatements($initModelCode);
            $code->ln();
            $view = $this->tableSchema->getRootSchema()->getView($data->model_varname.'.form-edit');
            $code->addStatements("\$data['title'] = 'Form Create {$data->label}';");
            $code->addStatements("\$data['{$data->model_varname}'] = \$this->resolveFormData(\${$data->model_varname});");
            foreach($fieldsHasRelation as $field) {
                $relation = $field->getRelation();
                $varName = $relation['var_name'];
                $colValue = $relation['col_value'];
                $colLabel = $relation['col_label'];
                $selectedColumns = $this->phpify([$colValue, $colLabel]);
                $relatedTable = $this->tableSchema->getRootSchema()->getTable($relation['table']);
                $relatedTableName = $relatedTable->getName();
                $listVarname = camel_case($relatedTableName);
                $repository = $this->getRepositoryPropertyName($relatedTable);
                $code->ln();
                $code->addStatements("
                    // Set {$varName}
                    \${$listVarname} = \$this->{$repository}->all(".$selectedColumns.");
                    \$data['{$varName}'] = array_map(function(\$record) {
                        return [
                            'value' => \$record['{$colValue}'],
                            'label' => \$record['{$colLabel}']
                        ];
                    }, \${$listVarname});
                ");
            }
            $code->ln();
            $code->addStatements("return view('{$view}', \$data);");
        });
    }

    protected function methodPostEdit(MethodGenerator $method)
    {
        $data = $this->getTableData();
        $method->addArgument('request', $data->request->class_update_with_namespace);
        $method->addArgument($data->primary_varname);
        $method->setDocblock(function($docblock) use ($data) {
            $docblock->addText("Update specified {$data->model_varname}");
            $docblock->addParam('request', $data->request->class_update_with_namespace);
            $docblock->addParam($data->primary_varname, 'string');
            $docblock->setReturn(static::CLASS_RESPONSE);
        });

        $initModelCode = $this->getInitModelCode();
        $method->setCode(function($code) use ($initModelCode, $data) {
            $inputFiles = $this->tableSchema->getInputFileFields();
            $code->addStatements($initModelCode);
            $code->ln();
            $code->addStatements("
                \$data = \$this->resolveFormInputs(\$request->all());
            ");
            $code->ln();
            foreach($inputFiles as $field) {
                $col = $field->getColumnName();
                $varName = camel_case($col);
                $path = $field->getUploadPath();
                $disk = $field->getUploadDisk();
                $code->addStatements("
                    // Uploading {$col}
                    \${$varName} = \$request->file('{$col}');
                    if (\${$varName}) {
                        \$filename = \${$varName}->getClientOriginalName();
                        \$path = '{$path}';
                        \$data['{$col}'] = \${$varName}->storeAs(\$path, \$filename, '{$disk}');
                    }
                ");
                $code->ln();
            }
            $code->addStatements("
                // Update data
                \$updated = \$this->{$data->repository->varname}->updateById(\${$data->primary_varname}, \$data);
                if (!\$updated) {
                    \$message = 'Something went wrong when update {$data->label}';
                    return back()->with('danger', \$message);
                }

                \$message = '{$data->label} has been updated!';
                return redirect()->route('{$data->route->page_list}')->with('info', \$message);
            ");
        });
    }

    protected function methodDelete(MethodGenerator $method)
    {
        $data = $this->getTableData();
        $method->addArgument('request', static::CLASS_REQUEST);
        $method->addArgument($data->primary_varname);
        $method->setDocblock(function($docblock) use ($data) {
            $docblock->addText("Delete specified {$data->model_varname}");
            $docblock->addParam('request', static::CLASS_REQUEST);
            $docblock->addParam($data->primary_varname, 'string');
            $docblock->setReturn(static::CLASS_RESPONSE);
        });

        $initModelCode = $this->getInitModelCode();
        $method->setCode(function($code) use ($initModelCode, $data) {
            $code->addStatements($initModelCode);
            $code->ln();
            $code->addStatements("
                // Delete data
                \$deleted = \$this->{$data->repository->varname}->deleteById(\${$data->primary_varname});
                if (!\$deleted) {
                    \$message = 'Something went wrong when delete {$data->label}';
                    return back()->with('danger', \$message);
                }

                \$message = '{$data->label} has been deleted!';
                return redirect()->route('{$data->route->page_list}')->with('info', \$message);
            ");
        });
    }

    protected function methodFindOrFail(MethodGenerator $method)
    {
        $data = $this->getTableData();
        $joins = [];
        $inputableFieldsHasRelation = $this->getInputableFieldsHasRelation();
        foreach($inputableFieldsHasRelation as $field) {
            $relation = $field->getRelation();
            $relatedTable = $this->tableSchema->getRootSchema()->getTable($relation['table']);
            $tableVarname = $relatedTable->getSingularName();
            $colLabel = $relation['col_label'];
            $colLabelAlias = $relation['col_alias'];

            $joins[] = [
                'table' => $relation['table'],
                'type' => 'inner',
                'key_from' => $relation['key_from'],
                'key_to' => $relation['key_to'],
                'selects' => [
                    $colLabelAlias? $colLabel.' as '.$colLabelAlias : $colLabel
                ],
            ];
        }

        $method->setVisibility(MethodGenerator::VISIBILITY_PROTECTED);
        $method->addArgument($data->primary_varname);
        $method->setDocblock(function($docblock) use ($data) {
            $docblock->addText("Find {$data->model_varname} by '{$data->primary_key}' or display 404 if not exists");
            $docblock->setReturn(static::CLASS_RESPONSE);
        });

        $method->setCode(function($code) use ($data, $joins) {
            if (!empty($joins)) {
                $code->addStatements("
                    \${$data->model_varname} = \$this->{$data->repository->varname}->findById(\${$data->primary_varname}, [
                        'joins' => ".$code->phpify($joins, true)."
                    ]);
                ");
            } else {
                $code->addStatements("
                    \${$data->model_varname} = \$this->{$data->repository->varname}->findById(\${$data->primary_varname});
                ");
            }

            $code->addStatements("
                if (!\${$data->model_varname}) {
                    return abort(404, '{$data->label} not found');
                }

                return \${$data->model_varname};
            ");
        });
    }

    protected function methodResolveFormInputs(MethodGenerator $method)
    {
        $resolveableFields = array_filter($this->tableSchema->getFields(), function($field) {
            return $field->hasInput() AND !empty($field->getInputResolver());
        });

        $method->setDocblock(function($docblock) {
            $docblock->addText('Resolve form inputs into storable data.');
            $docblock->addParam('inputs', 'array');
            $docblock->setReturn('array');
        });

        $method->addArgument('inputs', 'array');
        $method->setCode(function($code) use ($resolveableFields) {
            foreach($resolveableFields as $field) {
                $name = $field->getColumnName();
                $inputResolver = (new Stub($field->getInputResolver()))->render([
                    'value' => "\$inputs['{$name}']"
                ]);
                $code->addStatements("
                    // Resolve input {$name}
                    \$inputs['{$name}'] = {$inputResolver};
                ");
                $code->ln();
            }
            $code->addStatements("return \$inputs;");
        });
    }

    protected function methodResolveFormData(MethodGenerator $method)
    {
        $resolveableFields = array_filter($this->tableSchema->getFields(), function($field) {
            return $field->hasInput() AND !empty($field->getDataResolver());
        });

        $method->setDocblock(function($docblock) {
            $docblock->addText('Resolve data (form database) into form values.');
            $docblock->addParam('data', 'array');
            $docblock->setReturn('array');
        });

        $method->addArgument('data', 'array');
        $method->setCode(function($code) use ($resolveableFields) {
            foreach($resolveableFields as $field) {
                $name = $field->getColumnName();
                $inputResolver = (new Stub($field->getDataResolver()))->render([
                    'value' => "\$data['{$name}']"
                ]);
                $code->addStatements("
                    // Resolve input {$name}
                    \$data['{$name}'] = {$inputResolver};
                ");
                $code->ln();
            }
            $code->addStatements("return \$data;");
        });
    }

    protected function getInitModelCode()
    {
        $data = $this->getTableData();
        return "\${$data->model_varname} = \$this->findOrFail(\${$data->primary_varname});";
    }

    protected function getRequiredRepositories()
    {
        $repositories = [];
        $varName = $this->tableSchema->getSingularName();
        $repositories[$varName] = $this->tableSchema->getRepositoryInterface();
        $relations = $this->tableSchema->getRelations();
        foreach($relations as $relation) {
            $table = $relation['table'];
            $tableSchema = $this->tableSchema->getRootSchema()->getTable($table);
            $repositoryInterface = $tableSchema->getRepositoryInterface();
            $varName = $this->getRepositoryPropertyName($tableSchema);
            if (!in_array($repositoryInterface, $repositories)) {
                $repositories[$varName] = $repositoryInterface;
            }
        }

        return $repositories;
    }

    protected function getInputableFieldsHasRelation()
    {
        return array_filter($this->tableSchema->getFields(), function($field) {
            return !empty($field->getRelation()) AND $field->hasInput();
        });
    }

    protected function getRepositoryPropertyName(Table $table)
    {
        return camel_case($table->getSingularName());
    }

}
