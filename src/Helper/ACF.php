<?php

/*
 * Route middleware to easily implement multi-langue
 * todo: check to find a better way...
 */
namespace Rocket\Helper;

use Timber\Post, Timber\Image, Timber\User, Timber\Term;

class ACF
{
    private $raw_objects;


    public function __construct($post_id)
    {
        if( function_exists('get_field_objects') )
            $this->raw_objects = get_field_objects($post_id);
    }


    public function process()
    {
        return $this->clean( $this->raw_objects );
    }


    public function layoutAsKeyValue( $raw_layouts )
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


    public function bindLayoutFields($fields, $layouts){

        $data = [];
        $type = $fields['acf_fc_layout'];
        $layout = $layouts[$type];

        unset($fields['acf_fc_layout']);

        foreach ($fields as $name=>$value){

            $data[$name] = $layout[$name];
            $data[$name]['value'] = $value;
        }

        return $data;
    }


    public function clean($raw_objects)
    {
        $objects = [];

        if( !$raw_objects or !is_array($raw_objects) )
            return [];

        foreach ($raw_objects as $object) {
            
            switch ($object['type']) {

                case 'image';

                    if( empty($object['value']) )
                        break;

                    if ($object['return_format'] == 'id')
                        $objects[$object['name']] = new Image($object['value']);
                    elseif ($object['return_format'] == 'array')
                        $objects[$object['name']] = new Image($object['value']['id']);
                    else
                        $objects[$object['name']] = $object['value'];

                    break;

                case 'file';

                    if( empty($object['value']) )
                        break;

                    if ($object['return_format'] == 'id')
                        $objects[$object['name']] = wp_get_attachment_url( $object['value'] );
                    elseif ($object['return_format'] == 'array')
                        $objects[$object['name']] = $object['value']['url'];
                    else
                        $objects[$object['name']] = $object['value'];

                    break;

                case 'relationship';

                    $objects[$object['name']] = [];

                    if( is_array($object['value']) ){

                        foreach ($object['value'] as $value) {

                            if ($object['return_format'] == 'id')
                                $objects[$object['name']][] = new Post($value);
                            elseif ($object['return_format'] == 'object')
                                $objects[$object['name']][] = new Post($value->ID);
                            else
                                $objects[$object['name']][] = $object['value'];
                        }
                    }
                    break;

                case 'post_object';

                    if( empty($object['value']) )
                        break;

                    $objects[$object['name']] = new Post($object['value']->ID);
                    break;

                case 'user';

                    if( empty($object['value']) )
                        break;
                    
                    $objects[$object['name']] = new User($object['value']['ID']);
                    break;

                case 'flexible_content';

                    $objects[$object['name']] = [];

                    if( is_array($object['value']) ){

                        $layout = $this->layoutAsKeyValue($object['layouts']);

                        foreach ($object['value'] as $value) {

                            $type = $value['acf_fc_layout'];
                            $value = $this->bindLayoutFields($value, $layout);

                            $objects[$object['name']][] = ['type'=>$type, 'fields'=>$this->clean($value)];
                        }
                    }

                    break;

                case 'repeater';

                    $objects[$object['name']] = [];

                    if( is_array($object['value']) ){

                        foreach ($object['value'] as &$value) {
                            $i = 0;
                            foreach ($value as $id=>&$_value) {

                                $_tmp_value = $_value;
                                $_value = $object['sub_fields'][$i];
                                $_value['value'] = $_tmp_value;
                                $i++;
                            }
                        }
                    }

                    if( is_array($object['value']) ){

                        foreach ($object['value'] as $value) {

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
                                $objects[$object['name']][] = new Term($id);
                        }
                    }
                    break;

                default:
                    $objects[$object['name']] = $object['value'];
                    break;
            }
        }

        return $objects;
    }
}
