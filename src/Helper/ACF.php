<?php

/*
 * Route middleware to easily implement multi-langue
 * todo: check to find a better way...
 */
namespace Rocket\Helper;

use Rocket\Model\Post,
	Rocket\Model\Term;

use Timber\Image,
	Timber\User;

class ACF
{
	private $raw_objects, $objects;

	protected static $MAX_DEPTH = 1;
	protected static $DEPTH = 0;
	protected static $CACHE = [];

	public function __construct( $post_id )
	{
		if( ACF::$DEPTH > ACF::$MAX_DEPTH )
		{
			$this->objects = [];
		}
		else
		{
			++ACF::$DEPTH;

			$this->objects = $this->getCache('objects', $post_id);

			if( ACF::$DEPTH > 0 )
				--ACF::$DEPTH;
		}
	}


	public static function setMaxDepth( $value )
	{
		ACF::$MAX_DEPTH = $value;
	}


	public function get()
	{
		return $this->objects;
	}


	public function layoutsAsKeyValue($raw_layouts)
	{
		$layouts = [];

		foreach ($raw_layouts as $layout){

			$layouts[$layout['name']] = [];
			$subfields = $layout['sub_fields'];
			foreach ($subfields as $subfield){
				$layouts[$layout['name']][$subfield['name']] = $subfield;
			}
		}

		return $layouts;
	}


	public function bindLayoutsFields($fields, $layouts){

		$data = [];
		$type = $fields['acf_fc_layout'];
		$layout = $layouts[$type];

		unset($fields['acf_fc_layout']);

		foreach ($fields as $name=>$value){

			if( isset($layout[$name]) )
				$data[$name] = $layout[$name];

			$data[$name]['value'] = $value;
		}

		return $data;
	}


	public function layoutAsKeyValue( $raw_layout )
	{
		$data = [];

		foreach ($raw_layout as $value)
			$data[$value['name']] = $value;

		return $data;
	}


	public function bindLayoutFields($fields, $layout){

		$data = [];

		foreach ($fields as $name=>$value){

			if( isset($layout[$name]) )
				$data[$name] = $layout[$name];

			$data[$name]['value'] = $value;
		}

		return $data;
	}


	public function getCache($type, $id)
	{
		if( isset(ACF::$CACHE[$type], ACF::$CACHE[$type][$id]))
			return ACF::$CACHE[$type][$id];

		if( !isset(ACF::$CACHE[$type]) )
			ACF::$CACHE[$type] = [];

		$value = false;

		switch ($type)
		{
			case 'image':
				$value = new Image($id);
				break;

			case 'file':
				$value = apply_filters('rewrite_upload_url', wp_get_attachment_url( $id ));
				break;

			case 'product':
				$post_status = get_post_status( $id );
				$value = ( $post_status && $post_status !== 'publish' ) ? false : wc_get_product( $id );
				break;

			case 'post':
				$post_status = get_post_status( $id );
				$value = ( $post_status && $post_status !== 'publish' ) ? false : new Post( $id );
				break;

			case 'user':
				$value = new User( $id );
				break;

			case 'term':
				$value = new Term( $id );
				break;

			case 'objects':

				if( function_exists('get_field_objects') )
					$this->raw_objects = get_field_objects($id);
				else
					$this->raw_objects = [];

				$value = $this->clean( $this->raw_objects);

				break;
		}

		ACF::$CACHE[$type][$id] = $value;

		return $value;
	}


	public function clean($raw_objects)
	{
		$objects = [];

		if( !$raw_objects or !is_array($raw_objects) )
			return $objects;

		// Start analyzing

		foreach ($raw_objects as $object) {

			if(!isset($object['type']))
				continue;

			switch ($object['type']) {

				case 'clone';

					$layout = reset($object['sub_fields']);
					$value = reset($object['value']);

					$layout['value'] = $value;
					$value = $this->clean([$layout]);
					$objects[$object['name']] = reset($value);

					break;

				case 'image';
					if( empty($object['value']) )
						break;

					if ($object['return_format'] == 'id')
						$objects[$object['name']] = $this->getCache('image', $object['value']);
					elseif ($object['return_format'] == 'array')
						$objects[$object['name']] = $this->getCache('image', $object['value']['id']);
					else
						$objects[$object['name']] = $object['value'];

					break;

				case 'gallery';

					if( empty($object['value']) )
						break;

					if( is_array($object['value']) ){

						$objects[$object['name']] = [];

						foreach ($object['value'] as $value)
							$objects[$object['name']][] = $this->getCache('image', $value['id']);
					}

					break;

				case 'file';

					if( empty($object['value']) )
						break;

					if ($object['return_format'] == 'id')
						$objects[$object['name']] = $this->getCache('file', $object['value']);
					elseif ($object['return_format'] == 'array')
						$objects[$object['name']] = $object['value']['url'];
					else
						$objects[$object['name']] = $object['value'];

					break;

				case 'relationship';

					$objects[$object['name']] = [];

					if( is_array($object['value']) ){

						foreach ($object['value'] as $value) {

							$is_woo_product = count($object['post_type']) === 1 && $object['post_type'][0] == 'product' && class_exists( 'WooCommerce' );

							if ($object['return_format'] == 'id')
								$element = $is_woo_product ? ['product'=>$this->getCache('product', $value), 'post'=>$this->getCache('post', $value)] : $this->getCache('post', $value);
							elseif ($object['return_format'] == 'object')
								$element = $is_woo_product ? ['product'=>$this->getCache('product', $value->ID), 'post'=>$this->getCache('post', $value->ID)] : $this->getCache('post', $value->ID);
							else
								$element = $object['value'];

							if( $element )
								$objects[$object['name']][] = $element;
						}
					}
					break;

				case 'post_object';

					if( empty($object['value']) )
						break;

					if ($object['return_format'] == 'id')
						$objects[$object['name']] = $this->getCache('post', $object['value']);
					elseif ($object['return_format'] == 'object')
						$objects[$object['name']] = $this->getCache('post', $object['value']->ID);
					else
						$objects[$object['name']] = $object['value'];

					break;

				case 'user';

					if( empty($object['value']) )
						break;

					$objects[$object['name']] = $this->getCache('user', $object['value']['ID']);
					break;

				case 'flexible_content';

					$objects[$object['name']] = [];

					if( is_array($object['value']) ){

						$layouts = $this->layoutsAsKeyValue($object['layouts']);

						foreach ($object['value'] as $value) {
							$type = $value['acf_fc_layout'];
							$value = $this->bindLayoutsFields($value, $layouts);
							$objects[$object['name']][] = ['@type'=>$type, 'data'=>$this->clean($value)];
						}
					}

					break;

				case 'repeater';

					$objects[$object['name']] = [];

					if( is_array($object['value']) )
					{
						$layout = $this->layoutAsKeyValue($object['sub_fields']);

						foreach ($object['value'] as $value)
						{
							$value = $this->bindLayoutFields($value, $layout);
							$objects[$object['name']][] = $this->clean($value);
						}
					}

					break;

				case 'taxonomy';

					$objects[$object['name']] = [];

					if( is_array($object['value']) ){

						foreach ($object['value'] as $value) {

							$id = false;

							if ($object['return_format'] == 'id')
								$id = $value;
							elseif (is_object($value) && $object['return_format'] == 'object')
								$id = $value->term_id;

							if( $id )
								$objects[$object['name']][] = $this->getCache('term', $id);
						}
					}
					else{

						$id = false;

						if ($object['return_format'] == 'id')
							$id = $object['value'];
						elseif (is_object($object['value']) && $object['return_format'] == 'object')
							$id = $object['value']->term_id;

						if( $id )
							$objects[$object['name']] = $this->getCache('term', $id);
					}

					break;

				case 'select';

					if( !$object['multiple'] and is_array($object['value']) and count($object['value']) )
						$objects[$object['name']] = $object['value'][0];
					else
						$objects[$object['name']] = $object['value'];

					break;

				case 'group';

					$layout = $this->layoutAsKeyValue($object['sub_fields']);
					$value = $this->bindLayoutFields($object['value'], $layout);
					$objects[$object['name']] = $this->clean($value);

					break;

				default:

					$objects[$object['name']] = $object['value'];
					break;
			}
		}

		return $objects;
	}
}
