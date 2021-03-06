<?php
/**
 * User: Paul Coudeville <paul@metabolism.fr>
 */

namespace Rocket\Model;


use Rocket\Helper\ACF;
use Rocket\Model\Image;

/**
 * Class Post
 * @see \Timber\Post
 *
 * @package Rocket\Model
 */
class Post extends \Timber\Post
{
	public $excerpt, $thumbnail;

	/**
	 * Post constructor.
	 *
	 * @param null $id
	 */
	public function __construct($id = null) {

		if( is_object($id) )
		{
			if( !isset($id->ID) )
				return false;

			$id = $id->ID;
		}

		parent::__construct( $id );

		$this->excerpt = $this->post_excerpt;
		$this->thumbnail = new Image($this->_thumbnail_id);

		$this->clean();
		$this->hydrateCustomFields();
	}


	/**
	 * Add ACF custom fields as members of the post
	 */
	protected function hydrateCustomFields()
	{
		$custom_fields = new ACF( $this->ID );

		foreach ($custom_fields->get() as $name => $value )
		{
			$this->$name = $value;
		}
	}


	/**
	 * Add ACF custom fields as members of the post
	 */
	protected function clean()
	{
		foreach ($this as $key=>$value){

			if( substr($key,0,1) == '_' and $key != '_content' and $key != '_prev' and $key != '_next')
			{
				unset($this->$key);
				$key = substr($key,1);
				
				if( isset($this->$key) )
			    	unset($this->$key);
			}
		}

		unset(
			$this->guid, $this->post_content_filtered, $this->to_ping, $this->pinged, $this->ping_status,
			$this->ImageClass
		);
	}
}