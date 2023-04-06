<?php
// include auto-loader
include 'vendor/autoload.php';

use GraphQL\Client;

class DaggerPipeline {

  private $client;

  // utility function to run raw GraphQL queries
  // and recurse over result to return innermost leaf node
  private function executeQuery($query) {
    $response = $this->client->runRawQuery($query);
    $data = (array)($response->getData());
    foreach(new RecursiveIteratorIterator(
      new RecursiveArrayIterator($data), RecursiveIteratorIterator::LEAVES_ONLY) as $value) {
      $results[] = $value;
    }
    return $results[0];
  }

  // constructor
  public function __construct() {
    // initialize client with
    // endpoint from environment
    $sessionPort = getenv('DAGGER_SESSION_PORT') or throw new Exception("DAGGER_SESSION_PORT doesn't exist");
    $sessionToken = getenv('DAGGER_SESSION_TOKEN') or throw new Exception("DAGGER_SESSION_TOKEN doesn't exist");
    $this->client = new Client(
      'http://127.0.0.1:' . $sessionPort . '/query',
      ['Authorization' => 'Basic ' . base64_encode($sessionToken . ':')]
    );
    return;
  }

  // build image
  public function build() {
    // get host working directory
    $sourceQuery = <<<QUERY
    query {
      host {
        directory (path: ".", exclude: ["vendor", "ci"]) {
          id
        }
      }
    }
    QUERY;
    $sourceDir = $this->executeQuery($sourceQuery);

    // build runtime image
    // install tools and PHP extensions
    // configure Apache webserver root and rewriting
    $runtimeQuery = <<<QUERY
    query {
      container {
        from(address: "php:8.2-apache-buster") {
          withExec(args: ["apt-get", "update"]) {
            withExec(args: ["apt-get", "install", "--yes", "git-core"]) {
              withExec(args: ["apt-get", "install", "--yes", "zip"]) {
                withExec(args: ["docker-php-ext-install", "pdo", "pdo_mysql", "mysqli"]) {
                  withExec(args: ["sh", "-c", "sed -ri -e 's!/var/www/html!/var/www/public!g' /etc/apache2/sites-available/*.conf"]) {
                    withExec(args: ["sh", "-c", "sed -ri -e 's!/var/www/!/var/www/public!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf"]) {
                      withExec(args: ["a2enmod", "rewrite"]) {
                        id
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }
    }
    QUERY;
    $runtime = $this->executeQuery($runtimeQuery);

    // add application source code
    // set file permissions
    // set environment variables
    $appQuery = <<<QUERY
    query {
      container (id: "$runtime") {
        withMountedDirectory(path: "/mnt", source: "$sourceDir") {
          withWorkdir(path: "/mnt") {
            withExec(args: ["cp", "-R", ".", "/var/www"]) {
              withExec(args: ["chown", "-R", "www-data:www-data", "/var/www"]) {
                withExec(args: ["chmod", "-R", "777", "/var/www/storage"]) {
                  withEnvVariable(name: "APP_NAME", value: "Laravel with Dagger") {
                    withExec(args: ["chmod", "+x", "/var/www/docker-entrypoint.sh"]) {
                      id
                    }
                  }
                }
              }
            }
          }
        }
      }
    }
    QUERY;
    $app = $this->executeQuery($appQuery);

    // install Composer
    // add application dependencies
    $appWithDepsQuery = <<<QUERY
    query {
      container (id: "$app") {
        withExec(args: ["sh", "-c", "curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer"]) {
          withWorkdir(path: "/var/www") {
            withExec(args: ["composer", "install"]) {
              id
            }
          }
        }
      }
    }
    QUERY;
    $appWithDeps = $this->executeQuery($appWithDepsQuery);
    return $appWithDeps;
  }

  // test image
  public function test($image) {
    // create database service container
    $dbQuery = <<<QUERY
    query {
      container {
        from(address: "mariadb:10.11.2") {
          withEnvVariable(name: "MARIADB_DATABASE", value: "tdb") {
            withEnvVariable(name: "MARIADB_USER", value: "tuser") {
              withEnvVariable(name: "MARIADB_PASSWORD", value: "tpassword") {
                withEnvVariable(name: "MARIADB_ROOT_PASSWORD", value: "root") {
                  withExposedPort(port: 3306) {
                    withExec(args: []) {
                      id
                    }
                  }
                }
              }
            }
          }
        }
      }
    }
    QUERY;
    $db = $this->executeQuery($dbQuery);

    // bind database service to application image
    // set database credentials for application
    // run all PHPUnit tests
    $testQuery = <<<QUERY
    query {
      container (id: "$image") {
        withServiceBinding(alias: "test_db_service", service: "$db") {
          withEnvVariable(name: "DB_HOST", value: "test_db_service") {
            withEnvVariable(name: "DB_USERNAME", value: "tuser") {
              withEnvVariable(name: "DB_PASSWORD", value: "tpassword") {
                withEnvVariable(name: "DB_DATABASE", value: "tdb") {
                  withWorkdir(path: "/var/www") {
                    withExec(args: ["./vendor/bin/phpunit", "-vv"]) {
                      stdout
                    }
                  }
                }
              }
            }
          }
        }
      }
    }
    QUERY;
    $test = $this->executeQuery($testQuery);
    return $test;
  }

  // publish image to Docker Hub registry
  public function publish($image) {
    // retrieve Docker Hub registry credentials from host environment
    $registryUsername = getenv("REGISTRY_USERNAME");
    $registryPassword = getenv("REGISTRY_PASSWORD");

    // set registry password as Dagger secret
    $registryPasswordSecretQuery = <<<QUERY
    query {
      setSecret(name: "password", plaintext: "$registryPassword") {
        id
      }
    }
    QUERY;
    $registryPasswordSecret = $this->executeQuery($registryPasswordSecretQuery);

    // authenticate to Docker Hub registry
    // publish image
    $publishQuery = <<<QUERY
    query {
      container (id: "$image") {
        withEntrypoint(args: "/var/www/docker-entrypoint.sh") {
          withRegistryAuth(address: "docker.io", username: "$registryUsername", secret: "$registryPasswordSecret") {
            publish(address: "$registryUsername/laravel-dagger")
          }
        }
      }
    }
    QUERY;
    $address = $this->executeQuery($publishQuery);
    return $address;
  }

}

// run pipeline
try {
  $p = new DaggerPipeline();

  // build
  echo "Building image..." . PHP_EOL;
  $image = $p->build();
  echo "Image built." . PHP_EOL;

  // test
  echo "Testing image..." . PHP_EOL;
  $result = $p->test($image);
  echo "Image tested." . PHP_EOL;

  // publish
  echo "Publishing image..." . PHP_EOL;
  $address = $p->publish($image);
  echo "Image published at: $address" . PHP_EOL;
} catch (Exception $e) {
  print_r($e->getMessage());
  exit;
}
