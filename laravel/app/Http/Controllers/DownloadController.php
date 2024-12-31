<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DownloadController extends Controller
{
    public function incrementDownload(Request $request)
    {
        // Increment the download count in the database
        DB::table('downloads')->increment('count');

        // Return the new download count
        $count = DB::table('downloads')->value('count');
        return response()->json(['count' => $count]);
    }
}