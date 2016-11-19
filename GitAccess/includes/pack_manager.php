<?php

class GitPackfile
{
    public $objects;
    
    const OBJ_COMMIT = 1;
    const OBJ_TREE = 2;
    const OBJ_BLOB = 3;
    const OBJ_TAG = 4;
    const OBJ_REF_DELTA = 7;
    
    public function __construct($objects)
    {
        $this->objects = $objects;
    }
    
    public function exportPack()
    {
        $pack = 'PACK' . pack('N2N2', 2, count($this->objects));
        
        foreach ($this->objects as $object)
        {
            if (strlen($object['data']) < 16) // most significant bit is 0
            {
                $nibble_1 = substr(dechex($object['type'] << 4), 0, 1);
                $nibble_2 = substr(dechex(strlen($object['data'])), 0 , 1);
                $header = pack('H2', $nibble_1. $nibble_2);
                
                $pack .= $header . $object['data'];
            }
            else
            {
                $nibble_1 = substr(
                    dechex(($object['type'] << 4) | 128),
                    0,
                    1
                );
                $pack .= $nibble_1;
                
                $len = strlen($object['data']) << 4;
                $numSeptets = 0;
                while ($len > 0)
                {
                    $len = $len >> 7;
                    ++$numSeptets;
                }
                
                for ($i; $i <= $numSeptets; ++$i)
                {
                }
            }
        }
    }
    
    public static function importPack($pack, $index)
    {
        
    }
}
