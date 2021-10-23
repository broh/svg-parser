<?php

namespace XmlConfig;

class NodeBuilder
{
    const TYPE_OBJECT           = 'object';
    const TYPE_ARRAY            = 'array';
    const TYPE_ATOMIC           = 'atomic';
    const TYPE_COLLECTION       = 'collection';
    const TYPE_DYNAMIC_OBJECT   = 'dynamic';

    /** @var NodeBuilder */
    protected $root;

    /** @var NodeBuilder */
    protected $parent;

    /** @var NodeBuilder[] */
    protected $children = [];

    /** @var \SimpleXMLElement */
    protected $xml;

    /** @var string Name of the field */
    protected $name;

    /** @var \SimpleXMLElement[]|NodeBuilder[] Selected value */
    protected $value;

    /** @var string Type of the value */
    protected $type = self::TYPE_OBJECT;

    /** @var string Xpath to select the value */
    protected $xpath;

    /**
     * @var string
     *
     * [
     *     'value_filter' => callable($value),
     *     'name_filter' => callable($value)
     * ]
     *
     * See class LogoSvgImport for usage
     */
    protected $options;

    /**
     * NodeBuilder constructor.
     */
    public function __construct()
    {
        $this->root = $this;
        $this->parent = $this;
    }

    public function __clone()
    {
        $children = [];
        foreach ($this->children as $child) {
            $children[] = clone $child;
        }

        $this->children = $children;
    }

    /**
     * Adds new child node (field) to current object
     *
     * @param string $name
     * @param string $type
     * @param string $xpath
     * @param array $options
     *
     * @return NodeBuilder
     */
    public function field($name, $type, $xpath = null, $options = [])
    {
        $child = new NodeBuilder();
        $child
            ->setRoot($this->root)
            ->setParent($this)
            ->setName($name)
            ->setType($type)
            ->setXpath($xpath)
            ->setOptions($options);

        $this->children[] = $child;

        return $child;
    }

    /**
     * Returns parent of current node
     *
     * @return NodeBuilder
     */
    public function end()
    {
        return $this->parent;
    }

    /**
     * Applies xpath to xml for node and all it's children
     *
     * @return NodeBuilder
     */
    public function apply()
    {
        $this->name = $this->queryName();
        $this->value = $this->queryValue();

        $childXml = $this->value !== null ? $this->value : $this->xml;

        if ($this->isCollection() && is_array($childXml)) {
            $this->applyCollection($childXml);
        }

        foreach ($this->children as $child) {
            $child
                ->setXml($childXml)
                ->apply();
        }

        return $this->root;
    }

    /**
     * Reduces all nodes to a multidimensional array of atomic types
     *
     * @return array
     */
    public function getResult()
    {
        $result = $this->valueAsType($this->value);

        foreach ($this->children as $child) {
            $result = array_merge($result, $child->getResult());
        }

        // Do not add empty strings to result
        if (!$result) {
            return [];
        }

        if ($field = $this->getName()) {
            $result = [$field => $result];
        }

        return $result;
    }

    /**
     * Called for collection type only.
     * Applies all the children and sets them as a value of the node.
     *
     * @param \SimpleXMLElement[] $xml
     *
     * @throws \Exception
     */
    protected function applyCollection($xml)
    {
        if (!$this->isCollection()) {
            throw new \Exception(sprintf("Node of type %s can't be processed as collection type", $this->type));
        }

        $this->value = [];
        foreach ($xml as $descendant) {
            $item = [];
            foreach ($this->children as $child) {
                $newChild = clone $child;
                $newChild
                    ->setXml($descendant)
                    ->apply();

                $item[] = $newChild;
            }
            $this->value[] = $item;
        }

        $this->children = [];
    }

    /**
     * Applies current xpath to a xml and returns selection
     *
     * @return null|\SimpleXMLElement[]
     */
    protected function queryValue()
    {
        $value = null;

        if ($this->xml && $this->xpath) {
            if ($this->xpath === 'name()') {
                return $this->xml->xpath('.')[0]->getName();
            }

            $value = $this->xml->xpath($this->xpath);
        }

        return $value;
    }

    /**
     * Replaces xpath placeholder in the name if one specified
     *
     * @return null|string
     *
     * @throws \Exception
     */
    protected function queryName()
    {
        $name = $this->name;
        // looking for placeholder
        if (preg_match('/\{(?<xpath>.+)\}/', $name, $matches)) {
            $nameXpath = $matches['xpath'];

            // if count function specified
            if ($isCount = preg_match('/^count\((?<xpath>.+)\)/', $nameXpath, $matches)) {
                $nameXpath = $matches['xpath'];
            }

            $name = $this->xml->xpath($nameXpath);

            if ($isCount) {
                $name = count($name);
            } elseif (count($name)) {
                $name = $name[0]->__toString();
            }

            if ($name) {
                $name = preg_replace('/\{.+\}/', $name, $this->name);
            }
        }

        if (isset($this->options['name_filter'])) {
            $callback = $this->options['name_filter'];
            if (!is_callable($callback)) {
                throw new \Exception("Option 'name_filter' must be callable");
            }

            $name = $callback($name);
        }

        return $name;
    }

    /**
     * Converts $value according to type of node
     *
     * @param null|\SimpleXMLElement[] $value
     *
     * @return mixed
     *
     * @throws \Exception
     */
    protected function valueAsType($value)
    {
        $result = null;

        switch ($this->type) {
            case self::TYPE_OBJECT:
                $result = $this->getArrayValue($value);
                break;
            case self::TYPE_ARRAY:
                $result = $this->getArrayValue($value);
                break;
            case self::TYPE_ATOMIC:
                $result = $this->getAtomicValue($value);
                break;
            case self::TYPE_COLLECTION:
                $result = $this->getCollectionValue($value);
                break;
            case self::TYPE_DYNAMIC_OBJECT:
                $result = $this->getCollectionValue($value, true);
                break;
        }

        if ($result && isset($this->options['value_filter'])) {
            $callback = $this->options['value_filter'];
            if (!is_callable($callback)) {
                throw new \Exception("Option 'value_filter' must be callable");
            }

            $result = $callback($result);
        }

        return $result;
    }

    /**
     * @param null|\SimpleXMLElement[] $value
     *
     * @return array
     */
    protected function getArrayValue($value)
    {
        if (!$value) {
            return [];
        }

        $result = [];
        /** @var \SimpleXMLElement $item */
        foreach ($value as $item) {
            $result[] = $item->__toString();
        }

        return $result;
    }

    /**
     * @param null|NodeBuilder[]    $value
     * @param bool                  $assoc
     *
     * @return array
     */
    protected function getCollectionValue($value, $assoc = false)
    {
        if (!$value) {
            return [];
        }

        $result = [];
        foreach ($value as $children) {
            $item = [];
            /** @var NodeBuilder $child */
            foreach ($children as $child) {
                $item = array_merge($item, $child->getResult());
            }

            if ($assoc) {
                $result = array_merge_recursive($result, $item);
            } else {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @param null|\SimpleXMLElement[]  $value
     *
     * @return string
     */
    protected function getAtomicValue($value)
    {
        if (!$value) {
            return '';
        }

        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        /** @var \SimpleXMLElement $item */
        foreach ($value as $item) {
            $result[] = $item->__toString();
        }

        return implode(' ', $result);
    }

    /**
     * @return bool
     */
    protected function isCollection()
    {
        return $this->type === self::TYPE_COLLECTION || $this->type === self::TYPE_DYNAMIC_OBJECT;
    }


    /**     Setter / Getters    */

    /**
     * @return NodeBuilder
     */
    public function getRoot()
    {
        return $this->root;
    }

    /**
     * @param NodeBuilder $root
     *
     * @return $this
     */
    public function setRoot($root)
    {
        $this->root = $root;

        return $this;
    }

    /**
     * @return NodeBuilder
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * @param NodeBuilder $parent
     *
     * @return $this
     */
    public function setParent($parent)
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getXml()
    {
        return $this->xml;
    }

    /**
     * @param mixed $xml
     *
     * @return $this
     */
    public function setXml($xml)
    {
        $this->xml = $xml;

        return $this;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $name
     *
     * @return $this
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return string
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @param string $value
     *
     * @return $this
     */
    public function setValue($value)
    {
        $this->value = $value;

        return $this;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @param string $type
     *
     * @return $this
     */
    public function setType($type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return string
     */
    public function getXpath()
    {
        return $this->xpath;
    }

    /**
     * @param string $xpath
     *
     * @return $this
     */
    public function setXpath($xpath)
    {
        $this->xpath = $xpath;

        return $this;
    }

    /**
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * @param array $options
     *
     * @return $this
     */
    public function setOptions($options)
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @param NodeBuilder $childBuilder
     *
     * @return $this
     */
    public function addChild(NodeBuilder $childBuilder)
    {
        $this->children[] = $childBuilder;

        return $this;
    }

    public function getChild($name)
    {
        foreach ($this->children as $child) {
            if ($child->getName() == $name) {
                return $child;
            }
        }
    }
}
