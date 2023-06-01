<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class SearchController extends AbstractController
{

    /**
     * 
     * Global search functionality.
     *
     * Call this endpoint to get all the movies and TV shows related to the search request.
     * 
     * Example: /search?query=guardians&language=fr-FR&page=1
     * @author Thomas Ameye
     * @param string  $query Search query, this will be what we use to search movies and shows with.
     * @param int $page Optional page number for this search. Default '1'. Accepts one or more digits
     * @param string $language Optional language for the result text. Default 'en-US'. Accepts string, invalid strings will return results using 'en-US'
     * @param boolean $adult Optional whether adult content is enabled or not. Default 'false'.
     * @return array $result {
     *      Returns JSON string with these parameters
     *      @type int $page pagenumber of the current request
     *      @type array results array with current page results
     *      @type int total_pages total amount of pages for the current request
     *      @type int total_results total amount of results
     * }.
     */

    /**
     * @Route("/search", name="search")
     */
    public function search(Request $request, RateLimiterFactory $searchApiLimiter): JsonResponse
    {
        
        $limiter = $searchApiLimiter->create($request->getClientIp());
        if (false === $limiter->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => 'Rate limiting how fast you can trigger search'], 429);
        }

        $query = $request->query->get('query');

        // set default page nr to 1, handle if somehow an empty string gets passed as a page query
        $page = $request->query->get('page', 1);
        $page = !empty($page) && is_numeric($page) ? $page : 1;
        
        $language = $request->query->get('language','en-US');
        $adult = $request->query->get('adult',false);

        $client = HttpClient::create();
        try{
            $response = $client->request('GET', "https://api.themoviedb.org/3/search/multi", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $_ENV['TMDB_API_KEY'],
                    'accept' => 'application/json',
                ],
                'query' => [
                    'query' => $query,
                    'page' => $page,
                    'language' => $language,
                    'include_adult' => $adult
                ],
            ]);

            // handle rate limit errors
            $statusCode = $response->getStatusCode();
            $responsecontent = $response->getContent(false);
            if ($statusCode === 429) {
                return new JsonResponse(['error' => json_decode($responsecontent)], 429);
            }

            $movies = $response->toArray();
        
            return new JsonResponse($movies);
        }catch (HttpExceptionInterface $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        } 
    }


    /**
     * Get movie/tv show detail.
     *
     * Call this endpoint to get more details and potential trailers for a movie or TV show.
     * 
     * Example: /details/447365?type=movie&language=fr-FR (guardians of the galaxy vol. 3)
     * @author Thomas Ameye
     * @param int $movie_id Movie/TV show ID from the original 'search' request
     * @param string $type Type of content. Use the media_type from the original 'search' request. Defaults to 'movie'. Accepts 'movie' and 'tv'
     * @param string $language Optional language for the result text. Default 'en-US'. Accepts string, invalid strings will return results using 'en-US'
     * @return array $result {
     *      Returns JSON string with TMDB response plus additional parameter:
     *      @type array $trailers Found trailers for this movie/TV show (if any exist), otherwise empty. Contains the youtube key (eg. https://www.youtube.com/watch?v={insert_key_here})
     * }.
     */

    /**
     * @Route("/details/{id}", name="details", requirements={"id"="\d+"})
     */
    public function details(Request $request, RateLimiterFactory $detailApiLimiter, int $id): JsonResponse
    {
        $limiter = $detailApiLimiter->create($request->getClientIp());
        if (false === $limiter->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => 'Rate limiting how fast you can trigger detail search'], 429);
        }

        $language = $request->query->get('language','en-US');
        $type =  $request->query->get('type','movie');
        $client = HttpClient::create();
        try{
            $response = $client->request('GET', "https://api.themoviedb.org/3/{$type}/{$id}?language={$language}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $_ENV['TMDB_API_KEY'],
                    'accept' => 'application/json',
                ]
            ]);

            // handle non predicted errors. 429 is rate limiting from TMDB
            $statusCode = $response->getStatusCode();
            $responsecontent = $response->getContent(false);
            if ($statusCode !== 200) {
                return new JsonResponse(['error' => json_decode($responsecontent)], $statusCode);
            }
        
            $detail = $response->toArray();
            
            $trailers = $this->getTrailers($type,$id);
            $detail['trailers'] = $trailers;
            return new JsonResponse($detail);
        }
        catch (HttpExceptionInterface $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function getTrailers(string $type,int $id): ?array
    {
        $client = HttpClient::create();
        try {
            $response = $client->request('GET', "https://api.themoviedb.org/3/{$type}/{$id}/videos", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $_ENV['TMDB_API_KEY'],
                ],
            ]);

            // handle  non predicted errors. 429 is rate limiting from TMDB
            $statusCode = $response->getStatusCode();
            $responsecontent = $response->getContent(false);
            if ($statusCode !== 200) {
                return new JsonResponse(['error' => json_decode($responsecontent)], $statusCode);
            }

            $videos = $response->toArray()['results'];
            
            // Looking for all trailers on YouTube specifically
            $trailers=[];
            foreach ($videos as $video) {
                if ($video['type'] === 'Trailer' && $video['site']==='YouTube') {
                    $trailers[]=$video['key'];
                }
            }
            return $trailers; 
        } catch (HttpExceptionInterface $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}