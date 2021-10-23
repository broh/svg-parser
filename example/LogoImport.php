<?php

require __DIR__ . '/../NodeBuilder.php';

use XmlConfig\NodeBuilder;

class LogoParser {

    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * Парсим xml файл в массив данных
     * Настройки NodeBuilder задают структуру результирующего массива,
     * xpath выражения указывают путь к значению в xml файле
     * 
     * @return NodeBuilder
     */
    protected function getNodeDefinition(): NodeBuilder
    {
        $getNameCallback = function (string $name) {
            return preg_replace('/_\d+_$/', '', $name);
        };

        $builder = new NodeBuilder();

        return $builder
            ->field('REF', NodeBuilder::TYPE_OBJECT)
                ->field('ID', NodeBuilder::TYPE_ARRAY, "//svg:*[starts-with(@id, 'REF_')]//@id")->end()
            ->end()

            ->field('VIEW_PANELS', NodeBuilder::TYPE_OBJECT)
                ->field('ID', NodeBuilder::TYPE_ARRAY, "//svg:*[starts-with(@id, 'VIEW_PANELS')]/@id")->end()
                ->field('PANELS', NodeBuilder::TYPE_COLLECTION, "//svg:*[starts-with(@id, 'VIEW_PANELS')]/child::*")
                    ->field('NAME', NodeBuilder::TYPE_ATOMIC, '@id', [
                        'value_filter' => $getNameCallback,
                    ])->end()
                    ->field('ID', NodeBuilder::TYPE_ARRAY, '@id')->end()
                ->end()
            ->end()

            ->field('BACKGROUND', NodeBuilder::TYPE_OBJECT)
                ->field('ID', NodeBuilder::TYPE_ARRAY, "//svg:*[starts-with(@id, 'BACKGROUND')]/@id")->end()
                ->field('APPLICATIONS', NodeBuilder::TYPE_COLLECTION, "//svg:*[starts-with(@id, 'BACKGROUND')]/child::*")
                    ->field('NAME', NodeBuilder::TYPE_ATOMIC, '@id', [
                        'value_filter' => $getNameCallback,
                    ])->end()
                    ->field('ID', NodeBuilder::TYPE_ARRAY, '@id')->end()
                ->end()
            ->end()

            ->field('COLOUR_MODES', NodeBuilder::TYPE_DYNAMIC_OBJECT, "//svg:*[contains(@id, '_COLOUR_ZONE')]")
                ->field("{count(child::*[starts-with(@id, 'COLOUR')])}_ZONE", NodeBuilder::TYPE_OBJECT)
                    ->field('ID', NodeBuilder::TYPE_ARRAY, '@id')->end()
                    ->field('COLOUR_ZONES', NodeBuilder::TYPE_DYNAMIC_OBJECT, "./child::*[starts-with(@id, 'COLOUR_')]")
                        ->field("{@id}", NodeBuilder::TYPE_OBJECT, null, [
                                'name_filter' => $getNameCallback,
                            ])
                            ->field('ID', NodeBuilder::TYPE_ARRAY, '@id')->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    public function execute()
    {                
        $xml = new SimpleXMLElement($this->filePath, 0, true);
        $xml->registerXPathNamespace('svg', 'http://www.w3.org/2000/svg');        
        
        $result = $this
            ->getNodeDefinition()
            ->setXml($xml)
            ->apply()
            ->getResult();

        file_put_contents(__DIR__ . '/result.json', json_encode($result, JSON_PRETTY_PRINT));
    }
}

(new LogoParser(__DIR__ . '/logo.svg'))->execute();
