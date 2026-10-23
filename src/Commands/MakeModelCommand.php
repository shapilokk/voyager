<?php

namespace TCG\Voyager\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\ModelMakeCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

// Laravel 13: the parent class is registered as "make:model" by this attribute
#[AsCommand(name: 'voyager:make:model')]
class MakeModelCommand extends ModelMakeCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'voyager:make:model';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Voyager model class';

    /**
     * Laravel 13 defines make:model with a $signature, so $name and getOptions() are ignored.
     *
     * @param Filesystem $files
     */
    public function __construct(Filesystem $files)
    {
        if (isset($this->signature)) {
            $this->signature = preg_replace('/^make:model\b/', $this->name, $this->signature)
                ."\n{--d|softdelete : Add soft-delete field to Model}";
        }

        parent::__construct($files);
    }

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        return __DIR__.'/../../stubs/model.stub';
    }

    /**
     * Build the class with the given name.
     *
     * @param string $name
     *
     * @return string
     */
    protected function buildClass($name)
    {
        $stub = $this->files->get($this->getStub());

        return $this->addSoftDelete($stub)->replaceNamespace($stub, $name)->replaceClass($stub, $name);
    }

    /**
     * Add SoftDelete to the given stub.
     *
     * @param string $stub
     *
     * @return $this
     */
    protected function addSoftDelete(&$stub)
    {
        $traitIncl = $trait = '';

        if ($this->option('softdelete')) {
            $traitIncl = 'use Illuminate\Database\Eloquent\SoftDeletes;';
            $trait = 'use SoftDeletes;';
        }

        $stub = str_replace('//DummySDTraitInclude', $traitIncl, $stub);
        $stub = str_replace('//DummySDTrait', $trait, $stub);

        return $this;
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        $options = [
            ['softdelete', 'd', InputOption::VALUE_NONE, 'Add soft-delete field to Model'],
        ];

        return array_merge($options, parent::getOptions());
    }
}
