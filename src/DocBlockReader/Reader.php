<?php

namespace DocBlockReader;

class ReaderException extends \Exception
{
}

class Reader
{
	private $rawDocBlock;
	private $parameters;
	private $keyPattern = "[A-z0-9\_\-]+";
	private $endPattern = "[ ]*(?:@|\r\n|\n)";
	private $parsedAll = FALSE;

	protected $property = null;
	protected $method = null;
	protected $class = null;

	public function __construct($class)
	{
		$this->class = $class;
		$this->parameters[$this->class] = array();
	}	

	public function getClassDoc()
	{
		$this->property = null;
		$this->method = null;
		return $this->getDoc(new \ReflectionClass($this->class));
	}

	public function getPropertyDoc($property)
	{
		if (!property_exists($this->class, $property)) throw new \ReaderException("Property $property is not defined in class {$this->class}");
		$this->property = $property;
		$this->method = null;
		return $this->getDoc(new \ReflectionProperty($this->class, $property));
	}

	public function getMethodDoc($method)
	{
		if (!method_exists($this->class, $method)) throw new \ReaderException("Method $method is not defined in class {$this->class}");
		$this->property = null;
		$this->method = $method;
		return $this->getDoc(new \ReflectionMethod($this->class, $method));
	}

	protected function getDoc($reflection)
	{
		$this->rawDocBlock = $reflection->getDocComment();
		$this->parse();
		return $this->parameters[$this->getParsedType()];
	}

	protected function getParsedType()
	{
		if ($this->method or $this->property) return $this->method ? $this->method : $this->property;
		else return $this->class;
	}

	private function parseSingle($key)
	{
		if(isset($this->parameters[$this->getParsedType()][$key]))
		{
			return $this->parameters[$this->getParsedType()][$key];
		}
		else
		{
			if(preg_match("/@".preg_quote($key).$this->endPattern."/", $this->rawDocBlock, $match))
			{
				return TRUE;
			}
			else
			{
				preg_match_all("/@".preg_quote($key)." (.*)".$this->endPattern."/U", $this->rawDocBlock, $matches);
				$size = sizeof($matches[1]);

				// not found
				if($size === 0)
				{
					return NULL;
				}
				// found one, save as scalar
				elseif($size === 1)
				{
					return $this->parseValue($matches[1][0]);
				}
				// found many, save as array
				else
				{
					$this->parameters[$this->getParsedType()][$key] = array();
					foreach($matches[1] as $elem)
					{
						$this->parameters[$this->getParsedType()][$key][] = $this->parseValue($elem);
					}

					return $this->parameters[$this->getParsedType()][$key];
				}
			}
		}
	}

	private function parse()
	{
		$pattern = "/@(?=(.*)".$this->endPattern.")/U";

		preg_match_all($pattern, $this->rawDocBlock, $matches);

		foreach($matches[1] as $rawParameter)
		{
			if(preg_match("/^(".$this->keyPattern.") (.*)$/", $rawParameter, $match))
			{
				if(isset($this->parameters[$this->getParsedType()][$match[1]]))
				{
					$this->parameters[$this->getParsedType()][$match[1]] = array_merge((array)$this->parameters[$this->getParsedType()][$match[1]], (array)$match[2]);
				}
				else
				{
					$this->parameters[$this->getParsedType()][$match[1]] = $this->parseValue($match[2]);
				}
			}
			else if(preg_match("/^".$this->keyPattern."$/", $rawParameter, $match))
			{
				$this->parameters[$this->getParsedType()][$rawParameter] = TRUE;
			}
			else
			{
				$this->parameters[$this->getParsedType()][$rawParameter] = NULL;
			}
		}
	}

	public function getVariableDeclarations($name)
	{
		$declarations = (array)$this->getParameter($name);

		foreach($declarations as &$declaration)
		{
			$declaration = $this->parseVariableDeclaration($declaration, $name);
		}

		return $declarations;
	}

	private function parseVariableDeclaration($declaration, $name)
	{
		$type = gettype($declaration);

		if($type !== 'string')
		{
			throw new \InvalidArgumentException(
				"Raw declaration must be string, $type given. Key='$name'.");
		}

		if(strlen($declaration) === 0)
		{
			throw new \InvalidArgumentException(
				"Raw declaration cannot have zero length. Key='$name'.");
		}

		$declaration = explode(" ", $declaration);
		if(sizeof($declaration) == 1)
		{
			// string is default type
			array_unshift($declaration, "string");
		}

		// take first two as type and name
		$declaration = array(
			'type' => $declaration[0],
			'name' => $declaration[1]
		);

		return $declaration;
	}

	private function parseValue($originalValue)
	{
		if($originalValue && $originalValue !== 'null')
		{
			// try to json decode, if cannot then store as string
			if( ($json = json_decode($originalValue,TRUE)) === NULL)
			{
				$value = $originalValue;
			}
			else
			{
				$value = $json;
			}
		}
		else
		{
			$value = NULL;
		}

		return $value;
	}

	public function getParameters()
	{
		if(! $this->parsedAll)
		{
			$this->parse();
			$this->parsedAll = TRUE;
		}

		return $this->parameters;
	}

	public function getParameter($key)
	{
		return $this->parseSingle($key);
	}
}
