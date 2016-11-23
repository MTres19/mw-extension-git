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
            /* This finds the number of extra bytes the variable-length
             * integer will take up. Since the first bit of each byte
             * is reserved, only 7 bits are available (a septet). 4 bits
             * can be stored with the byte holding the object type, so
             * this method removes 4 significant bits at first, then cycles
             * through a loop, removing 7 significant bits at a time until
             * all the significant bits are gone (i.e. $lenTest == 0).
             */
            $lenTest = $object['length'] >> 4;
            $numSeptets = 0;
            while ($lenTest > 0)
            {
                $lenTest = $lenTest >> 7;
                ++$numSeptets;
            }
            
            /* This creates an array of octets for the variable-length int.
             * It works "backward" because the int is big endian and doesn't
             * necessarily take up all the bytes on the "left" side. This
             * does not include the first 4 bits of the int which are stored
             * in the first octet ($octets[0]).
             */
            $len = $object['length'];
            $octets = array();
            for ($i = $numSeptets; $i > 0; --$i)
            {
                /* This first isolates the $i-th septet by applying an AND
                 * operation to $len based on 127 (1111111 in binary) shifted
                 * left by a certain number of bits. The result is then
                 * shifted back to the least significant position for use as
                 * a single octet.
                 */
                $octets[$i] = ($len & (127 << (7 * ($numSeptets - $i)))) >> (7 * ($numSeptets -$i));
                
                /* If an octet is not the last of the variable-length integer,
                 * its most significant bit will be set.
                 */
                if ($i != $numSeptets)
                {
                    $octets[$i] = $octets[$i] | 128;
                }
            }
            
            // This sets the object type and the first four bits of the length.
            $octets[0] = $object['type'] << 4 | ($len >> ($numSeptets * 7));
            
            // If the length doesn't fit in 4 bits, set the MSB for variable-length int.
            if ($len > 15)
            {
                $octets[0] = $octets[0] | 128;
            }
            
            ksort($octets);
        }
    }
    
    public static function importPack($pack, $index)
    {
        
    }
}
