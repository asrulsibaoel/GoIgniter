<?php defined('BASEPATH') OR exit('No direct script access allowed');

// HMVC routing without HMVC, MODULEPATH should be defined in index.php

(defined('EXT')) OR define('EXT', '.php');

// define MY_Router::$locations if undefined
if(MY_Router::$locations === NULL)
{
    // chop APPPATH and MODULEPATH into array
    $appPathParts = explode('/', APPPATH);
    $modulePathParts = explode('/', MODULEPATH);

    // calculate testIndex
    $testIndex = count($appPathParts)-1;
    while($testIndex >= 0)
    {
        if(array_slice($appPathParts, 0, $testIndex) == array_slice($modulePathParts, 0, $testIndex))
        {
            break;
        }
        else
        {
            $testIndex --;
        }
    }

    // create offsetParts
    $offsetParts = array();
    for($i=0; $i<(count($appPathParts)-$testIndex); $i++)
    {
        $offsetParts[] = '..';
    }
    $offsetParts = array_merge($offsetParts, array_slice($modulePathParts, $testIndex));

    // create offset
    $offset = implode('/', $offsetParts);

    MY_Router::$locations = array(
        MODULEPATH => $offset,
    );
}

class MY_Router extends CI_Router{

    public static $moduleRoutes;
    public static $locations;

    public $module;
	private $located = 0;

    /** Load a module file **/
	public static function load_file($file, $path, $type = 'other', $result = TRUE)	
	{
		$file = str_replace(EXT, '', $file);		
		$location = $path.$file.EXT;
		
		if ($type === 'other') 
		{			
			if (class_exists($file, FALSE))	
			{
				log_message('debug', "File already loaded: {$location}");				
				return $result;
			}	
			include_once $location;
		} 
		else 
		{
			/* load config or language array */
			include $location;

			if ( ! isset($$type) OR ! is_array($$type))				
				show_error("{$location} does not contain a valid {$type} array");

			$result = $$type;
		}
		log_message('debug', "File loaded: {$location}");
		return $result;
	}

	/** 
	* Find a file
	* Scans for files located within modules directories.
	* Also scans application directories for models, plugins and views.
	* Generates fatal error if file not found.
	**/
	public static function find($file, $module, $base) 
	{
		$segments = explode('/', $file);

		$file = array_pop($segments);
		$file_ext = (pathinfo($file, PATHINFO_EXTENSION)) ? $file : $file.EXT;
		
		$path = ltrim(implode('/', $segments).'/', '/');	
		$module ? $modules[$module] = $path : $modules = array();
		
		if ( ! empty($segments)) 
		{
			$modules[array_shift($segments)] = ltrim(implode('/', $segments).'/','/');
		}	
        
        foreach(MY_Router::$locations as $location => $offset)
        {
            foreach($modules as $module => $subpath) 
            {			
                $fullpath = $location.$module.'/'.$base.$subpath;

                if ($base == 'libraries/' OR $base == 'models/')
                {
                    if(is_file($fullpath.ucfirst($file_ext))) return array($fullpath, ucfirst($file));
                }
                else
                    /* load non-class files */
                    if (is_file($fullpath.$file_ext)) return array($fullpath, $file);
            }
        }
		
		return array(FALSE, $file);	
	}
	
	/** Parse module routes **/
	public static function parse_routes($module, $uri) 
	{
		/* load the route file */
        if ( ! isset(self::$moduleRoutes) )
        {
            self::$moduleRoutes = array();
        }

		if ( ! isset(self::$moduleRoutes[$module])) 
		{
			if (list($path) = self::find('routes', $module, 'config/'))
			{
				$path && self::$moduleRoutes[$module] = self::load_file('routes', $path, 'route');
			}
		}

		if ( ! isset(self::$moduleRoutes[$module])) return;
			
		/* parse module routes */
		foreach (self::$moduleRoutes[$module] as $key => $val) 
		{						
			$key = str_replace(array(':any', ':num'), array('.+', '[0-9]+'), $key);
			
			if (preg_match('#^'.$key.'$#', $uri)) 
			{							
				if (strpos($val, '$') !== FALSE AND strpos($key, '(') !== FALSE) 
				{
					$val = preg_replace('#^'.$key.'$#', $val, $uri);
				}
				return explode('/', $module.'/'.$val);
			}
		}
	}

    public function fetch_module()
	{
		return $this->module;
	}

	protected function _set_request($segments = array())
	{
		if ($this->translate_uri_dashes === TRUE)
		{
			foreach(range(0, 2) as $v)
			{
				isset($segments[$v]) && $segments[$v] = str_replace('-', '_', $segments[$v]);
			}
		}
		
		$segments = $this->locate($segments);

		if($this->located == -1)
		{
			$this->_set_404override_controller();
			return;
		}

		if(empty($segments))
		{
			$this->_set_default_controller();
			return;
		}
		
		$this->set_class($segments[0]);
		
		if (isset($segments[1]))
		{
			$this->set_method($segments[1]);
		}
		else
		{
			$segments[1] = 'index';
		}
       
		array_unshift($segments, NULL);
		unset($segments[0]);
		$this->uri->rsegments = $segments;
	}
	
	protected function _set_404override_controller()
	{
		$this->_set_module_path($this->routes['404_override']);
	}

	protected function _set_default_controller()
	{
		if (empty($this->directory))
		{
			/* set the default controller module path */
			$this->_set_module_path($this->default_controller);
		}

		parent::_set_default_controller();
		
		if(empty($this->class))
		{
			$this->_set_404override_controller();
		}
	}

	/** Locate the controller **/
	public function locate($segments)
	{
		$this->located = 0;
		$ext = $this->config->item('controller_suffix').EXT;

		/* use module route if available */
		if (isset($segments[0]) && $routes = MY_Router::parse_routes($segments[0], implode('/', $segments)))
		{
			$segments = $routes;
		}

		/* get the segments array elements */
		list($module, $directory, $controller) = array_pad($segments, 3, NULL);

		/* check modules */
		foreach (MY_Router::$locations as $location => $offset)
		{
			/* module exists? */
			if (is_dir($source = $location.$module.'/controllers/'))
			{
				$this->module = $module;
				$this->directory = $offset.$module.'/controllers/';

				/* module sub-controller exists? */
				if($directory)
				{
					/* module sub-directory exists? */
					if(is_dir($source.$directory.'/'))
					{	
						$source .= $directory.'/';
						$this->directory .= $directory.'/';

						/* module sub-directory controller exists? */
						if($controller)
						{
							if(is_file($source.ucfirst($controller).$ext))
							{
								$this->located = 3;
								return array_slice($segments, 2);
							}
							else $this->located = -1;
						}
					}
					else
					if(is_file($source.ucfirst($directory).$ext))
					{
						$this->located = 2;
						return array_slice($segments, 1);
					}
					else $this->located = -1;
				}

				/* module controller exists? */
				if(is_file($source.ucfirst($module).$ext))
				{
					$this->located = 1;
					return $segments;
				}
			}
		}

		if( ! empty($this->directory)) return;

		/* application sub-directory controller exists? */
		if($directory)
		{
			if(is_file(APPPATH.'controllers/'.$module.'/'.ucfirst($directory).$ext))
			{
				$this->directory = $module.'/';
				return array_slice($segments, 1);
			}

			/* application sub-sub-directory controller exists? */
			if($controller)
			{ 
				if(is_file(APPPATH.'controllers/'.$module.'/'.$directory.'/'.ucfirst($controller).$ext))
				{
					$this->directory = $module.'/'.$directory.'/';
					return array_slice($segments, 2);
				}
			}
		}

		/* application controllers sub-directory exists? */
		if (is_dir(APPPATH.'controllers/'.$module.'/'))
		{
			$this->directory = $module.'/';
			return array_slice($segments, 1);
		}

		/* application controller exists? */
		if (is_file(APPPATH.'controllers/'.ucfirst($module).$ext))
		{
			return $segments;
		}
		
		$this->located = -1;
	}

	/* set module path */
	protected function _set_module_path(&$_route)
	{
		if ( ! empty($_route))
		{
			// Are module/directory/controller/method segments being specified?
			$sgs = sscanf($_route, '%[^/]/%[^/]/%[^/]/%s', $module, $directory, $class, $method);
			
			// set the module/controller directory location if found
			if ($this->locate(array($module, $directory, $class)))
			{
				//reset to class/method
				switch ($sgs)
				{
					case 1:	$_route = $module.'/index';
						break;
					case 2: $_route = ($this->located < 2) ? $module.'/'.$directory : $directory.'/index';
						break;
					case 3: $_route = ($this->located == 2) ? $directory.'/'.$class : $class.'/index';
						break;
					case 4: $_route = ($this->located == 3) ? $class.'/'.$method : $method.'/index';
						break;
				}
			}
		}
	}

	public function set_class($class)
	{
		$class = $class.$this->config->item('controller_suffix');
		parent::set_class($class);
	}
}
