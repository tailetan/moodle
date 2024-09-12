<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tiny_recordrtc;

/**
 * Constants for Tiny RecordRTC plugin.
 *
 * @package    tiny_recordrtc
 * @copyright  2024 Huong Nguyen <huongnv13@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class constants {

    /** @var string TINYRECORDRTC_AUDIO_TYPE The audio recording type. */
    public const TINYRECORDRTC_AUDIO_TYPE = 'audio';

    /** @var string TINYRECORDRTC_VIDEO_TYPE The video recording type. */
    public const TINYRECORDRTC_VIDEO_TYPE = 'video';

    /** @var string TINYRECORDRTC_SCREEN_TYPE The screen-sharing recording type. */
    public const TINYRECORDRTC_SCREEN_TYPE = 'screen';

    /** @var string TINYRECORDRTC_RES_240_180 240 x 180 resolution (4:3). */
    public const TINYRECORDRTC_RES_240_180 = '240,180';

    /** @var string TINYRECORDRTC_RES_320_180 320 x 180 resolution (16:9). */
    public const TINYRECORDRTC_RES_320_180 = '320,180';

    /** @var string TINYRECORDRTC_RES_320_240 320 x 240 resolution (4:3). */
    public const TINYRECORDRTC_RES_320_240 = '320,240';

    /** @var string TINYRECORDRTC_RES_426_240 426 x 240 resolution (16:9). */
    public const TINYRECORDRTC_RES_426_240 = '426,240';

    /** @var string TINYRECORDRTC_RES_384_288 384 x 288 resolution (4:3). */
    public const TINYRECORDRTC_RES_384_288 = '384,288';

    /** @var string TINYRECORDRTC_RES_512_288 512 x 288 resolution (16:9). */
    public const TINYRECORDRTC_RES_512_288 = '512,288';

    /** @var string TINYRECORDRTC_RES_480_360 480 x 360 resolution (4:3). */
    public const TINYRECORDRTC_RES_480_360 = '480,360';

    /** @var string TINYRECORDRTC_RES_640_360 640 x 360 resolution (16:9). */
    public const TINYRECORDRTC_RES_640_360 = '640,360';

    /** @var string TINYRECORDRTC_RES_576_432 576 x 432 resolution (4:3). */
    public const TINYRECORDRTC_RES_576_432 = '576,432';

    /** @var string TINYRECORDRTC_RES_640_480 640 x 480 resolution (4:3). */
    public const TINYRECORDRTC_RES_640_480 = '640,480';

    /** @var string TINYRECORDRTC_RES_768_432 768 x 432 resolution (16:9). */
    public const TINYRECORDRTC_RES_768_432 = '768,432';

    /** @var string TINYRECORDRTC_RES_768_576 768 x 576 resolution (4:3). */
    public const TINYRECORDRTC_RES_768_576 = '768,576';

    /** @var string TINYRECORDRTC_RES_1280_720 1280 x 720 resolution (16:9). */
    public const TINYRECORDRTC_RES_1280_720 = '1280,720';

    /** @var string TINYRECORDRTC_RES_1024_768 1024 x 768 resolution (4:3). */
    public const TINYRECORDRTC_RES_1024_768 = '1024,768';

    /** @var string TINYRECORDRTC_RES_1920_1080 1920 x 1080 (16:9). */
    public const TINYRECORDRTC_RES_1920_1080 = '1920,1080';
}
