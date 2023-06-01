# dept-assignment
API for searching movies, TV shows and their details including trailers

## Install

    download the codebase

## Run the app
    
    cd into the project directory
    php -S localhost:9912 -t public
    
# API Endpoints

## Search for TV shows and movies

### Request

`GET /search?query={searchQuery}&language={locale}&page={pageNr}`

## Get details for TV show and movies

### Request

`GET /details/{id}?type={typeOfContent}&language={locale}`
