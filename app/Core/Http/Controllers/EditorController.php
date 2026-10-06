<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\Request;
use App\Core\Support\Upload;
use App\Core\Support\ValidationException;

/** Pictures dropped into the rich-text editor. Returns the file's address so the editor can show it. */
class EditorController extends Controller
{
    public function image(): never
    {
        $file = Request::file('image') ?? throw new ValidationException(['image' => __('Choose an image.')]);
        $path = Upload::image($file, 'editor', 3072);       // folder public/uploads/editor, png/jpg/webp, 3 MB
        json_response(['url' => url('uploads/'.$path)]);
    }
}
