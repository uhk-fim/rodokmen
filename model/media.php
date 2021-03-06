<?php
namespace Rodokmen;
use \R;


class Media extends Pod
{
	const thumbW = 210;
	const thumbH = 175;
	const viewW = 1920;
	const viewH = 1080;
	const maxSize = 8388608;  // 8MB
	// "640K ought to be enough for anybody."

	public function __construct() { parent::__construct('media'); }

	public function findAllByYear()
	{
		return $this->findAll('ORDER BY year DESC');
	}

	static public function findWithPersons($person1, $person2, $shuffle = false)
	{
		$m = new self();

		$ids = R::getAll('SELECT mp1.media_id FROM media_person AS mp1
		                  JOIN media_person AS mp2 ON mp1.media_id = mp2.media_id
		                  WHERE mp1.person_id = ? AND mp2.person_id = ?',
		                  array($person1->id, $person2->id));

		$ids = \array_map(function($row)
		{
			return \intval($row['media_id']);
		}, $ids);

		if ($shuffle) \shuffle($ids);
		return $m->fromIds($ids);
	}
}

class ModelMedia extends \RedBean_SimpleModel
{
	private static $image_formats = array(
		'image/png',
		'image/gif',
		'image/jpeg',
		'image/pjpeg'
	);

	const url_image_orig  = 'media/photo/original/';
	const url_image_thumb = 'media/photo/thumb/';
	const url_image_view  = 'media/photo/view/';

	const view_threshold_size = 1048576; // 1MB

	private static function media_fn($url_dir, $file_id, $ext)
	{
		// Data path is hardcoded for media, because it's also hardcoded in .htaccess
		return __DIR__.'/../data/'.$url_dir.$file_id.'.'.$ext;
	}

	private static function unlink_files($file_id, $ext)
	{
		@\unlink(self::media_fn(self::url_image_orig, $file_id, $ext));
		@\unlink(self::media_fn(self::url_image_thumb, $file_id, 'jpg'));
		@\unlink(self::media_fn(self::url_image_view, $file_id, 'jpg'));
	}

	private function add_image($upload)
	{
		//Create thumbnail and view image, store original

		$tmp_fn = $upload['tmp_name'];
		$ext = \strtolower(\pathinfo($upload['name'], PATHINFO_EXTENSION));
		$unique_name = \uniqid('', true);
		$orig_fn  = self::media_fn(self::url_image_orig, $unique_name, $ext);
		$thumb_fn = self::media_fn(self::url_image_thumb, $unique_name, 'jpg');
		$view_fn  = self::media_fn(self::url_image_view, $unique_name, 'jpg');

		if (!\move_uploaded_file($tmp_fn, $orig_fn)) return false;

		try
		{
			$thumb = \PhpThumbFactory::create($orig_fn);
			$thumb->adaptiveResizeQuadrant(Media::thumbW, Media::thumbH, 'T');
			$thumb->save($thumb_fn, 'jpg');

			$view  = \PhpThumbFactory::create($orig_fn);
			if (\filesize($tmp_fn) > self::view_threshold_size) $view->resize(Media::viewW, Media::viewH);
			$view->save($view_fn, 'jpg');
		}
		catch (\Exception $e)
		{
			self::unlink_files($unique_name, $ext);
			return false;
		}

		$this->type = 'image';
		$this->file_id = $unique_name;
		$this->orig_ext = $ext;

		return true;
	}

	public function delete()
	{
		R::trashAll($this->ownMediaPerson);
	}

	public function after_delete()
	{
		self::unlink_files($this->file_id, $this->orig_ext);
	}

	public function edit($input, $upload = false)
	{
		$rules = array(
				array('required', array('rdk_year')),
				array('integer', 'rdk_year')
			);

		if ($upload)
		{
			$rules[] = array('required', array('rdk_uploadfile'));
			$rules[] = array('file', 'rdk_uploadfile', Media::maxSize, self::$image_formats);
		}

		$d = Pod::validate($input, $rules);
		if ($upload && !$this->add_image($input['rdk_uploadfile'])) throw new ValidationError('rdk_uploadfile');

		$this->year = $d['rdk_year'];
		$this->comment = $d['rdk_comment'];

		// Person-Media tags:
		$tags = \json_decode($d['rdk_tags'], true);
		if ($tags && \array_key_exists('tags', $tags))
		{
			$pod_p = new Person();
			$this->bean->sharedPersonList = array();

			foreach ($tags['tags'] as $tag)
			{
				$person = $pod_p->fromId($tag);
				if ($person) $this->bean->sharedPersonList[] = $person;
			}
		}
	}

	public function origFilename()
	{
		return $this->media_fn(self::url_image_orig, $this->file_id, $this->orig_ext);
	}

	public function thumbUrl()
	{
		return self::url_image_thumb.$this->file_id.'.jpg';
	}

	public function viewUrl()
	{
		return self::url_image_view.$this->file_id.'.jpg';
	}

	public function tags($json = false)
	{
		$tags = array();

		foreach ($this->bean->sharedPersonList as $person)
		{
			$tags[] = $person->idName();
		}

		if ($json)
			return \json_encode(array('tags' => $tags ? $tags : false), JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS);
		else
			return $tags;
	}
}
