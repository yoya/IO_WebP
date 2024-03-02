<?php

/*
  IO_WebP class - 1.0.2
  (c) 2019/08/22 yoya@awm.jp
  ref) http://pwiki.awm.jp/~yoya/?WebP
  https://developers.google.com/speed/webp/docs/riff_container
  https://tools.ietf.org/html/rfc6386#page-121
 */

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/Bit.php';
}

class IO_WebP {
    var $_webpdata = null;
    var $FileHeader = null;
    var $ChunkList = null;
    function parse($webpdata) {
        $this->_webpdata = $webpdata;
        $bit = new IO_Bit();
        $bit->input($webpdata);
        // FileHader
        $this->FileHeader = [
            "FourCC" => $bit->getData(4),
            "Size" => $bit->getUI32LE(),
            "Signature"  => $bit->getData(4),
        ];
        $this->ChunkList = [];
        while ($bit->hasNextData(4)) {
            $this->ChunkList []= $this->parseChunk($bit);
        }
    }
    function parseChunk($bit) {
        list($base_offset, $dummy) = $bit->getOffset();
        $ChunkHeader = $bit->getData(4);
        $chunk = ["Header" => $ChunkHeader];
        switch ($ChunkHeader) {
        case "VP8 ":
            $tagSize = $bit->getUI32LE();
            $chunk["TagSize"] = $tagSize;
            $tmp = $bit->getUI8() | ($bit->getUI8() << 8) | ($bit->getUI8() << 16);
            $chunk["key_frame"] = $tmp & 0x1;
            $chunk["version"] = ($tmp >> 1) & 0x7;
            $chunk["show_frame"] = ($tmp >> 4) & 0x1;
            $chunk["first_part_size"] = ($tmp >> 5) & 0x7FFFF;
            $start_code = $bit->getData(3);
            $tmp = $bit->getUI16LE();
            $chunk["width"] = $tmp & 0x3FF;
            $chunk["horizontal_scale"] = $tmp >> 14;
            $tmp = $bit->getUI16LE();
            $chunk["height"] = $tmp & 0x3FF;
            $chunk["vertical_scale"] = $tmp >> 14;
            break;
        case "VP8L": // Lossless
            $tagSize = $bit->getUI32LE();
            $chunk["TagSize"] = $tagSize;
            $chunk["image_width"] = $bit->getUIBits(14) + 1;
            $chunk["image_height"] = $bit->getUIBits(14) + 1;
            $chunk["alpha_is_used"] = $bit->getUIBit();
            $chunk["version_number"] = $bit->getUIBits(3);
            break;
        case "VP8X": // Extended
            $tagSize = $bit->getUI32LE();
            $chunk["TagSize"] = $tagSize;
            $chunk["Rsv"] = $bit->getUIBits(2);  // reserved
            $chunk["I"] = $bit->getUIBits(1);  // ICC Profild
            $chunk["L"] = $bit->getUIBits(1);  // Alpha plane
            $chunk["E"] = $bit->getUIBits(1);  // Exif
            $chunk["X"] = $bit->getUIBits(1);  // XMP
            $chunk["A"] = $bit->getUIBits(1);  // Animation
            $chunk["R"] = $bit->getUIBits(1);  // reserved
            $chunk["Reserved"] = $bit->getUIBits(24);
            $chunk["image_width"] = $bit->getUI24LE();
            $chunk["image_height"] = $bit->getUI24LE();
            break;
        default:
            $tagSize = $bit->getUI32LE();
            $chunk["TagSize"] = $tagSize;
            break;
        }
        $bit->setOffset($base_offset + $tagSize + 8, 0);
        return $chunk;
    }
    
     function dump($opts) {
         echo "FourCC:".$this->FileHeader["FourCC"];
         echo " Size:".$this->FileHeader["Size"];
         echo " Signature:".$this->FileHeader["Signature"];
         echo PHP_EOL;
         foreach ($this->ChunkList as $chunk) {
             $Header = $chunk["Header"];
             echo "Header:".$Header." TagSize:".$chunk["TagSize"];
             echo PHP_EOL;
             switch ($Header) {
             case "VP8 ":
                 echo "    key_frame:".$chunk["key_frame"];
                 echo " version:".$chunk["version"] ;
                 echo " show_frame:".$chunk["show_frame"];
                 echo " first_part_size:".$chunk["first_part_size"];
                 echo PHP_EOL;
                 echo "    width:".$chunk["width"];
                 echo " horizontal_scale:".$chunk["horizontal_scale"];
                 echo " height:".$chunk["height"];
                 echo " vertical_scale:".$chunk["vertical_scale"];
                 break;
             case "VP8L":
                 echo "    image_width:".$chunk["image_width"];
                 echo " image_height:".$chunk["image_height"];
                 break;
             case "VP8X":
                 echo "    I(icc):".$chunk["I"];
                 echo " L(alpha):".$chunk["L"];
                 echo " E(exif):".$chunk["E"];
                 echo " X(xmp):".$chunk["X"];
                 echo " A(anim):".$chunk["A"];
                 echo PHP_EOL;
                 echo "    image_width:".$chunk["image_width"];
                 echo " image_height:".$chunk["image_height"];
                 break;
             case "ALPH":
                 echo "    (alpha data)";
                 break;
             }
             echo PHP_EOL;
         }
     }
}
