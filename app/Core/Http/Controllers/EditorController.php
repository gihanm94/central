<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Support\EditorImages;
use App\Core\Support\Request;
use App\Core\Support\Upload;
use App\Core\Support\ValidationException;

/** Pictures dropped into the rich-text editor: saved as a file, the editor gets its address (the text holds the link, never base64). */
class EditorController extends Controller
{
    public function image(): never
    {
        $file = Request::file('image') ?? throw new ValidationException(['image' => __('Choose an image.')]);
        $path = Upload::image($file, 'editor', 3072);       // folder public/uploads/editor, png/jpg/webp, 3 MB
        try {
            EditorImages::uploaded($path, $this->user()->id);
            EditorImages::sweep();                            // and away with pictures of forms never saved
        } catch (\Throwable) {
            // the picture is saved; the registry only decides when it may be deleted
        }
        json_response(['url' => url('uploads/'.$path)]);
    }
}
