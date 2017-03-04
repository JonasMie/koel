<?php

namespace App\Services;

use App\Console\Commands\SyncMedia;
use App\Events\LibraryChanged;
use App\Libraries\WatchRecord\WatchRecordInterface;
use App\Models\Album;
use App\Models\Artist;
use App\Models\File;
use App\Models\Playlist;
use App\Models\Setting;
use App\Models\Song;
use App\Models\User;
use CFPropertyList\CFPropertyList;
use getID3;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\Finder;

class Media
{
    /**
     * All applicable tags in a media file that we cater for.
     * Note that each isn't necessarily a valid ID3 tag name.
     *
     * @var array
     */
    protected $allTags = [
        'artist',
        'album',
        'title',
        'length',
        'track',
        'lyrics',
        'cover',
        'mtime',
        'compilation',
    ];

    /**
     * Tags to be synced.
     *
     * @var array
     */
    protected $tags = [];

    public function __construct()
    {
    }

    /**
     * Sync the media. Oh sync the media.
     *
     * @param string|null $path
     * @param array       $tags The tags to sync.
     *                                 Only taken into account for existing records.
     *                                 New records will have all tags synced in regardless.
     * @param array       $substrings The substrings to replace within the iTunes media paths
     * @param bool        $force Whether to force syncing even unchanged files
     * @param SyncMedia   $syncCommand The SyncMedia command object, to log to console if executed by artisan.
     */
    public function sync($path = null, $tags = [], $substrings = [], $force = false, SyncMedia $syncCommand = null)
    {
        if (!app()->runningInConsole()) {
            set_time_limit(config('koel.sync.timeout'));
        }

        $path = $path ?: Setting::get('media_path');
        $this->setTags($tags);

        $results = [
            'good' => [], // Updated or added files
            'bad'  => [], // Bad files
            'ugly' => [], // Unmodified files
        ];

        $getID3 = new getID3();

        // Check if iTunes Library file is given as media path. If so, get its content
        if (ends_with($path, ".xml") && is_file($path)) {
            $plist = $this->gatheriTunesFiles($path);
            if (key_exists('Tracks', $plist)) {
                $files = $plist['Tracks'];
            }
        } else {
            $files = $this->gatherFiles($path);
        }

        if ($syncCommand) {
            $syncCommand->createProgressBar(count($files));
        }

        foreach ($files as $file) {
            $itunes_id = null;
            if (is_array($file)) {
                $itunes_id = $file['Track ID'];
                $file = $this->normalizePath($file['Location'], $substrings);
            }

            $file = new File($file, $getID3);

            $song = $file->sync($this->tags, $force, $itunes_id);

            if ($song === true) {
                $results['ugly'][] = $file;
            } elseif ($song === false) {
                $results['bad'][] = $file;
            } else {
                $results['good'][] = $file;
            }

            if ($syncCommand) {
                $syncCommand->updateProgressBar();
                $syncCommand->logToConsole($file->getPath(), $song, $file->getSyncError());
            }
        }

        // Delete non-existing songs.
        $hashes = array_map(function ($f) {
            return self::getHash($f->getPath());
        }, array_merge($results['ugly'], $results['good']));

        Song::whereNotIn('id', $hashes)->delete();

        // Sync iTunes playlists.
        if (isset($plist) && $plist['Playlists']) {
            if ($syncCommand) {
                $syncCommand->info(PHP_EOL . PHP_EOL . 'Koel iTunes playlist syncing started.' . PHP_EOL);
                $syncCommand->createProgressBar(count($plist['Playlists']));
                $user = User::whereIsAdmin(true)->first();
            } else {
                $user = auth()->user();
            }
            $it_playlist_ids = [];
            foreach ($plist['Playlists'] as $it_playlist) {
                $it_playlist_ids[] = $it_playlist['Playlist ID'];

                if ((key_exists("Visible", $it_playlist) && !$it_playlist['Visible']) || !key_exists("Playlist Items", $it_playlist)) {
                    if ($syncCommand) {
                        $syncCommand->updateProgressBar();
                        $syncCommand->logPlaylistToConsole($it_playlist['Name'], true);
                    }
                    continue;
                }
                $it_playlist_id = $it_playlist['Playlist ID'];
                $playlist = $user->playlists()->whereItunesId($it_playlist_id)->first();
                if (!$playlist) {
                    $playlist = $user->playlists()->create(["name" => $it_playlist['Name'], "itunes_id" => $it_playlist_id]);
                }

                $playlist_tracks = [];
                foreach ($it_playlist['Playlist Items'] as $playlistItem) {
                    $playlist_tracks[] = $playlistItem['Track ID'];
                }
                $res = $playlist->songs()->sync(Song::whereIn('itunes_id', $playlist_tracks)->get(['id']));
                $result = !(count($res['attached']) || count($res['detached']) || count($res['updated']));

                if ($syncCommand) {
                    $syncCommand->updateProgressBar();
                    $syncCommand->logPlaylistToConsole($it_playlist['Name'], $result);
                }
            }
            Playlist::whereNotIn('itunes_id', $it_playlist_ids)->delete();
        }

        // Trigger LibraryChanged, so that TidyLibrary handler is fired to, erm, tidy our library.
        event(new LibraryChanged());
    }

    /**
     * Gather all applicable files in a given directory.
     *
     * @param string $path The directory's full path
     *
     * @return array An array of SplFileInfo objects
     */
    public function gatherFiles($path)
    {
        return Finder::create()
            ->ignoreUnreadableDirs()
            ->files()
            ->followLinks()
            ->name('/\.(mp3|ogg|m4a|flac)$/i')
            ->in($path);
    }


    private function gatheriTunesFiles($path)
    {
        return $plist = (new CFPropertyList($path, CFPropertyList::FORMAT_XML))->toArray();
    }

    /**
     * Sync media using a watch record.
     *
     * @param WatchRecordInterface $record The watch record.
     * @param SyncMedia|null       $syncCommand The SyncMedia command object, to log to console if executed by artisan.
     */
    public function syncByWatchRecord(WatchRecordInterface $record, SyncMedia $syncCommand = null)
    {
        Log::info("New watch record received: '$record'");
        $path = $record->getPath();

        if ($record->isFile()) {
            Log::info("'$path' is a file.");

            // If the file has been deleted...
            if ($record->isDeleted()) {
                // ...and it has a record in our database, remove it.
                if ($song = Song::byPath($path)) {
                    $song->delete();

                    Log::info("$path deleted.");

                    event(new LibraryChanged());
                } else {
                    Log::info("$path doesn't exist in our database--skipping.");
                }
            }
            // Otherwise, it's a new or changed file. Try to sync it in.
            // File format etc. will be handled by File::sync().
            elseif ($record->isNewOrModified()) {
                $result = (new File($path))->sync($this->tags);
                Log::info($result instanceof Song ? "Synchronized $path" : "Invalid file $path");
            }

            return;
        }

        // Record is a directory.
        Log::info("'$path' is a directory.");

        if ($record->isDeleted()) {
            // The directory is removed. We remove all songs in it.
            if ($count = Song::inDirectory($path)->delete()) {
                Log::info("Deleted $count song(s) under $path");
                event(new LibraryChanged());
            } else {
                Log::info("$path is empty--no action needed.");
            }
        } elseif ($record->isNewOrModified()) {
            foreach ($this->gatherFiles($path) as $file) {
                (new File($file))->sync($this->tags);
            }

            Log::info("Synced all song(s) under $path");
        }
    }

    /**
     * Construct an array of tags to be synced into the database from an input array of tags.
     * If the input array is empty or contains only invalid items, we use all tags.
     * Otherwise, we only use the valid items in it.
     *
     * @param array $tags
     */
    public function setTags($tags = [])
    {
        $this->tags = array_intersect((array)$tags, $this->allTags) ?: $this->allTags;

        // We always keep track of mtime.
        if (!in_array('mtime', $this->tags, true)) {
            $this->tags[] = 'mtime';
        }
    }

    /**
     * Generate a unique hash for a file path.
     *
     * @param $path
     *
     * @return string
     */
    public function getHash($path)
    {
        return File::getHash($path);
    }

    /**
     * Tidy up the library by deleting empty albums and artists.
     */
    public function tidy()
    {
        $inUseAlbums = Song::select('album_id')->groupBy('album_id')->get()->lists('album_id')->toArray();
        $inUseAlbums[] = Album::UNKNOWN_ID;
        Album::whereNotIn('id', $inUseAlbums)->delete();

        $inUseArtists = Album::select('artist_id')->groupBy('artist_id')->get()->lists('artist_id')->toArray();

        $contributingArtists = Song::distinct()
            ->select('contributing_artist_id')
            ->groupBy('contributing_artist_id')
            ->get()
            ->lists('contributing_artist_id')
            ->toArray();

        $inUseArtists = array_merge($inUseArtists, $contributingArtists);
        $inUseArtists[] = Artist::UNKNOWN_ID;
        $inUseArtists[] = Artist::VARIOUS_ID;

        Artist::whereNotIn('id', array_filter($inUseArtists))->delete();
    }

    private function normalizePath($path, $substrings = [])
    {
        if (count($substrings) % 2 == 0) {
            $i = 0;
            while ($i < count($substrings)) {
                $path = str_replace($substrings[$i], $substrings[$i + 1], $path);
                $i += 2;
            }
        }
        return urldecode(str_replace("file://", "", $path));
    }
}
