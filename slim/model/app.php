<?php
namespace Rodokmen;
use \Slim;


class LogMiddleware extends \Slim\Middleware
{
	public function call()
	{
		$env = $this->app->environment;
		$env['slim.errors'] = fopen(__DIR__.'/../data/log', 'a');
		$this->next->call();
	}
}

class App extends Slim\Slim
{
	const name = 'Rodokmen';

	private $config;
	private $usr;

	// private function get_locale()
	// {
	// 	// TODO: detect based on the Accept-Language header
	// 	return 'en_GB';
	// }

	private function setup_twig()
	{
		$this->view->parserOptions = array(
			'charset' => 'utf-8',
			'cache' => __DIR__.'/../cache',
			'auto_reload' => true,
			'strict_variables' => false,
			'autoescape' => true
		);
		$this->view->parserExtensions = array(new \Slim\Views\TwigExtension());

		// l18n disabled for now, see also require.all.php

		// $this->view->getInstance()->addExtension(new \Twig_Extensions_Extension_I18n());

		// $locale = $this->get_locale();
		// putenv('LC_ALL='.$locale);
		// setlocale(LC_ALL, $locale);
		// bindtextdomain($this->getName(), __DIR__.'/../view/locale');
		// bind_textdomain_codeset($this->getName(), 'UTF-8');
		// textdomain($this->getName());
	}

	private function setup_db()
	{
		Db::setup($this->config->db_auth,
							$this->config->db_data,
							$this->config->db_freeze === true);
	}

	private function setup_modes()
	{
		$this->configureMode('development', function ()
		{
			$this->config(array
			(
				'log.enabled' => true,
				'log.level' => \Slim\Log::DEBUG,
				'debug' => true
			));
		});

		$this->configureMode('production', function ()
		{
			$this->config(array
			(
				'log.enabled' => true,
				'log.level' => \Slim\Log::NOTICE,
				'debug' => false
			));
			$this->environment['Rodokmen.force_https'] = true;
		});
	}

	public function __construct($name = self::name)
	{
		parent::__construct(array
		(
			'mode' => 'developement',
			'view' => new \Slim\Views\Twig(),
			'templates.path' => __DIR__.'/../view'
		));

		$this->log->setWriter(new Logger($this, __DIR__.'/../data/log'));

		$this->setName($name);
		self::$apps[$name] = $this;

		$this->config = new Config();

		$this->setup_twig();
		$this->setup_db();
		$this->setup_modes();

		$this->usr = User::fromSession();
	}

	public function __destruct()
	{
		Db::close();
	}

	public static function getApp($name = self::name)
	{
		return parent::getInstance($name);
	}

	public function user()
	{
		return $this->usr;
	}

	public function conf()
	{
		return $this->config;
	}
};
