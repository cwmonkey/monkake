<?php

// for templates
class T {
	public static function firstof() {
		$args = func_get_args();
		foreach ( $args as $arg ) {
			if ( !empty($arg) ) {
				return $arg;
			}
		}

		return '';
	}
}

class M {
	// Static
	private static $_m;

	public static function Get($key, $val = null) {
		if ( isset(self::$_m->_config[$key]) ) return self::$_m->_config[$key];
		return $val;
	}

	public static function Set($key, $val) {
		return self::$_m->_config[$key] = $val;
	}

	private static function _init_obj() {
		if ( !self::$_m ) {
			self::$_m = new self();
			session_start();
		}
	}

	public static function AddConfig($__file) {
		self::_init_obj();
		require($__file);
		unset($__file);
		self::$_m->add_config(get_defined_vars());
	}

	public static function Run() {
		try {
			$request = new MonkakeRequest();
			$request->dispatch();
		} catch (Exception $e) {
			$request->handle_error($e);
		}
	}

	public static function Init($request, $url = null, $method = null, $route = null) {
		$request->method = $_SERVER['REQUEST_METHOD'];
		self::_init_obj();

		if ( $url === null ) $url = explode('?', $_SERVER['REQUEST_URI']);
		if ( $method === null ) $method = $_SERVER['REQUEST_METHOD'];
		if ( $route === null ) $route = self::route($url, $method);

		if ( !$route ) {
			$route = M::Get('route_404_config');
		} elseif ( isset($route['forward']) ) {
			$forward = $route['forward'];
			foreach ( $route as $key => $val ) {
				if ( $key == 'constraints' || $key == 'forward' ) continue;
				$forward = str_replace(':' . $key, $val, $forward);
			}
			header('Location: ' . $forward, true, 301);
		}

		$request->route = $route;

		$controller = @$route['controller'];
		$action = @$route['action'];
		if ( !$action ) {
			$methods = ( @$route['methods'] ) ? @$route['methods'] : M::Get('method_actions');
			$action = @$methods[$request->method];
		}

		$controller_obj = self::GetController($controller);

		if ( !method_exists($controller_obj, M::Get('action_prepend') . $route['action']) ) {
			throw new Exception('Couldn\'t load action ' . $route['action'] .  ' from ' . $controller);
		} else {
			$controller_obj->Init($request);
		}
	}

	public static function GetController($controller) {
		$controller_class = explode('/', $controller);
		$controller_class = array_pop($controller_class);
		$controller_class = self::Get('controller_prepend') . $controller_class;

		if ( !class_exists($controller_class) ) {
			$loading_file = self::Get('monkake_dir') . self::Get('controller_dir') . $controller . self::Get('controller_append');
			if ( !$loading_file || !file_exists($loading_file) || !require_once($loading_file) ) {
				throw new Exception('Couldn\'t load controller from ' . $loading_file);
			}
		}

		$controller_obj = new $controller_class();
		return $controller_obj;
	}

	public static function GetView($view, $request) {
		$view_class = explode('/', $view);
		$view_class = array_pop($view_class);
		$view_class = self::Get('view_prepend') . $view_class;

		if ( !class_exists($view_class) ) {
			$loading_file = self::Get('monkake_dir') . self::Get('view_dir') . $view . self::Get('view_append');
			if ( !$loading_file || !file_exists($loading_file) || !require_once($loading_file) ) {
				throw new Exception('Couldn\'t load view from ' . $loading_file);
			}
		}

		$view_obj = new $view_class($request);
		return $view_obj;
	}

	static function _r($path, $route, $config) {
		while (substr($path, 0, 1) == '/') {
			$path = substr($path, 1);
		}

		while (substr($route, 0, 1) == '/') {
			$route = substr($route, 1);
		}

		if ( $route == '' ) {
			if ( $path == '' ) {
				return $config;
			} else {
				return false;
			}
		}

		if ( !preg_match_all("/^([^\(]*)?((\((.+)\)))?/", $route, $matches) ) {
			return false;
		}

		$path_components = preg_split('/\//', $path);
		$route_components = null;
		$i = 0;
		$constraints = ( isset($config['constraints']) ) ? $config['constraints'] : null;
		$star_count = 0;

		if ( $matches[1][0] ) {
			while (substr($matches[1][0], 0, 1) == '/') {
				$matches[1][0] = substr($matches[1][0], 1);
			}

			$route_components = preg_split('/\//', $matches[1][0]);

			$good_route = true;
			$path_components = array_pad($path_components, count($route_components), '');

			foreach ($route_components as $route_component) {
				$component_name = ( preg_match('/^[a-z]/', $route_component) ) ? $route_component : substr($route_component, 1);
				$constraint = ( isset($constraints[$component_name]) ) ? $constraints[$component_name] : null;
				$path_component = $path_components[$i];

				if ( substr($route_component, 0, 1) == ':' && (!$constraint || ($constraint && preg_match($constraint, $path_component))) ) {
					if ( $path_component != '' ) {
						$config[$component_name] = $path_component;
					} else {
						return false;
					}
				// Allow for multiple sections to be rolled into one component
				} elseif ( substr($route_component, 0, 1) == '*' ) {
					$had_one = false;
					for ( $pi = $i; $pi < count($path_components); $pi++ ) {
						$pcomp = $path_components[$pi];
						if ( $constraint && !preg_match($constraint, $pcomp) ) {
							//$in_star = false;
							break;
						} else {
							$config[$component_name][] = $path_components[$pi];
							$config[$route_component] = true;
							$star_count++;
							$i++;
							$had_one = true;
						}
					}

					if ( $had_one ) {
						$i--;
						$star_count--;
					} else {
						return false;
					}
				} elseif ( $route_component == $path_component ) {
					$config[$route_component] = true;
				} else {
					return false;
				}

				$i++;
			}
		}

		// $matches[4][0] is the matched component, example /:action
		if ( isset($matches[4][0]) && $matches[4][0] ) {
			$path = implode(array_splice($path_components, $i), '/');
			$new_config = self::_r($path, $matches[4][0], $config);

			if ( !$new_config ) {
				return $config;
			} else {
				return $new_config;
			}
		} else {
			if ( !$route_components || count($route_components) == count($path_components) - $star_count /*|| $in_star*/ ) {
				return $config;
			} else {
				return false;
			}
		}
	}

	static function route($url, $method) {
		$path = strtolower($url[0]);
		$route_config = null;

		while ( substr($path, -1) == '/' ) {
			$path = substr($path,0,(strlen($path)-1));
		}
		$routes = self::Get('routes', array());
		foreach ( $routes as $route => $config ) {
			$route_config = self::_r($path, $route, $config);
			if ( $route_config ) break;
		}

		return $route_config;
	}

	public static function autoload($name) {
		if ( strstr($name, '\\') ) {
			$file = self::Get('monkake_dir') . self::Get('lib_dir') . str_replace('\\', '/', $name) . '.php';
			if ( file_exists( $file ) ) {
				require_once($file);
				return;
			}
		}

		$file = __DIR__ . '/app/lib/' . str_replace('\\', '/', $name) . '.php';
		if ( file_exists( $file ) ) {
			require_once($file);
			return;
		}

		$file = self::Get('monkake_dir');
		$loading_file = null;
		$class_file = $file . self::Get('class_dir') . $name . self::Get('class_append');
		$loading = null;

		if ( strpos($name, self::Get('controller_prepend')) === 0 ) {
			$loading = 'controller';
			$name = str_replace(self::Get('controller_prepend'), '', $name);
		} elseif ( strpos($name, self::Get('model_prepend')) === 0 ) {
			$loading = 'model';
			$name = str_replace(self::Get('model_prepend'), '', $name);
		} elseif ( strpos($name, self::Get('view_prepend')) === 0 ) {
			$loading = 'view';
			$name = str_replace(self::Get('view_prepend'), '', $name);
		}

		if ( $loading ) {
			$loading_file = $file . self::Get($loading . '_dir') . $name . self::Get($loading . '_append');
			if ( !$loading_file || !file_exists($loading_file) || !require_once($loading_file) ) {
				//throw new Exception('Unable to load class: ' . $name . ' as ' . $loading_file);
			}
		} else if ( !$class_file || !file_exists($class_file) || !require_once($class_file) ) {
			//throw new Exception('Unable to load class: ' . $name . ' as ' . $class_file);
		}
	}

	public static function url($name, $options = null) {
		$urls = self::Get('urls', array());
		if ( isset($urls[$name]) ) {
			return $urls[$name]['url'];
		} else {
			throw new Exception('Url not set');
		}
	}

	// Non-static
	private $_config = array();

	private function add_config($config) {
		$this->_config = array_merge($this->_config, $config);
	}
}

class monkake_view {
	public function RenderCssFiles($css_files, $echo = false) {
		if ( !is_array($css_files) ) $css_files = array($css_files);

		if ( !M::Get('minify_css') ) {
			$cssextra = ( M::Get('debug') ) ? '?' . time() : '';
			foreach ( $css_files as $file ) {
				if ( strstr($file, '//') ) {
					echo '<link rel="stylesheet" type="text/css" href="' . $file . $cssextra . '" />';
				} else {
					echo '<link rel="stylesheet" type="text/css" href="/css/' . $file . $cssextra . '" />';
				}
			}
			return;
		}

		$output = '';
		$lastmod = 0;
		$files = array();

		foreach ( $css_files as $file ) {
			$path = M::Get('css_dir') . $file;
			if ( ($mtime = filemtime($path)) ) {
				if ( $lastmod < $mtime ) $lastmod = $mtime;
				$files[] = $file;
			}
		}

		if ( $echo ) {
			foreach ( $files as $file ) {
				$path = M::Get('css_dir') . $file;
				$output .= "\n" . file_get_contents($path);
			}

			echo '<style>' . Minify_CSS_Compressor::process($output) . '</style>';
		} elseif ( count($files) ) {
			$filename = M::Get('css_compressed_dir') . md5(implode(',', $files)) . '.css';

			if ( intval(@filemtime($filename)) < $lastmod ) {
				foreach ( $files as $file ) {
					$path = M::Get('css_dir') . $file;
					$output .= "\n" . file_get_contents($path);
				}

				$fp = fopen($filename, 'w+');
				fwrite($fp, Minify_CSS_Compressor::process($output));
				fclose($fp);
				chmod($filename, 0777);
			}

			echo '<link rel="stylesheet" type="text/css" href="/css/' . $lastmod . '/' . md5(implode(',', $files)) . '.css" />';
		}
	}
}

class monkake_controller {
	public function is_mobile() {
		//$wurflDir = dirname(__FILE__) . '/../lib/WURFL';
		$wurflDir = M::Get('monkake_dir') . '../../monkake/app/lib/WURFL';
		//$resourcesDir = dirname(__FILE__) . '/../resources';
		$resourcesDir = M::Get('monkake_dir') . '../../monkake/app/resources';
		require_once $wurflDir.'/Application.php';
		 
		$persistenceDir = $resourcesDir.'/storage/persistence';
		$cacheDir = $resourcesDir.'/storage/cache';
		 
		// Create WURFL Configuration
		$wurflConfig = new WURFL_Configuration_InMemoryConfig();
		 
		// Set location of the WURFL File
		$wurflConfig->wurflFile($resourcesDir.'/wurfl.zip');
		 
		// Set the match mode for the API ('performance' or 'accuracy')
		$wurflConfig->matchMode('performance');
		 
		// Automatically reload the WURFL data if it changes
		$wurflConfig->allowReload(true);
		 
		// Optionally specify which capabilities should be loaded
		$wurflConfig->capabilityFilter(array(
			// "device_os",
			// "device_os_version",
			// "is_tablet",
			"is_wireless_device",
			// "mobile_browser",
			// "mobile_browser_version",
			// "pointing_method",
			// "preferred_markup",
			// "resolution_height",
			// "resolution_width",
			// "ux_full_desktop",
			// "xhtml_support_level",
		));
		 
		// Setup WURFL Persistence
		$wurflConfig->persistence('file', array('dir' => $persistenceDir));
		 
		// Setup Caching
		$wurflConfig->cache('file', array('dir' => $cacheDir, 'expiration' => 36000));
		 
		// Create a WURFL Manager Factory from the WURFL Configuration
		$wurflManagerFactory = new WURFL_WURFLManagerFactory($wurflConfig);
		 
		// Create a WURFL Manager
		/* @var $wurflManager WURFL_WURFLManager */
		$wurflManager = $wurflManagerFactory->create();

		$requestingDevice = $wurflManager->getDeviceForHttpRequest($_SERVER);

		return ($requestingDevice->getCapability('is_wireless_device') == 'true');
	}
}

spl_autoload_register(function ($cname) {
    M::autoload($cname);
});

/*function __autoload($name) {
	M::autoload($name);
}*/

class MonkakeRequest {
	public $output_type = 'html';
	public $route;
	public $method;

	public function dispatch() {
		M::Init($this);
	}

	public function internal_redirect($url, $method) {
		M::Init($this, $url, $method);
	}

	public function trigger_404() {
		header('HTTP/1.0 404 Not Found');
		M::Init($this, null, null, '');
	}

	public function handle_error($e) {
		switch($this->output_type) {
			case 'xml':
				
			default:
				var_dump($e);
				//echo $e->message;
		}
	}
}
