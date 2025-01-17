#!/bin/bash -e

# Define the PHP versions
PHP_VERSIONS=("7.4" "8.0" "8.1" "8.2" "8.3" "8.4")

# Loop through each PHP version
for VERSION in "${PHP_VERSIONS[@]}"; do
  DOCKERFILE="./docker/Dockerfile-${VERSION}"
  IMAGE_NAME="php-ci:${VERSION}"

  # Check if the Dockerfile exists
  if [ -f "$DOCKERFILE" ]; then
    echo "Building Docker image for PHP ${VERSION}..."
    docker build -f "$DOCKERFILE" -t "$IMAGE_NAME" -q .

    if [ $? -eq 0 ]; then
      echo "Successfully built ${IMAGE_NAME}. Running tests..."
      docker run --rm "$IMAGE_NAME" './test.sh'

      if [ $? -eq 0 ]; then
        echo "Tests passed for PHP ${VERSION}."
      else
        echo "Tests failed for PHP ${VERSION}." >&2
      fi
    else
      echo "Failed to build Docker image for PHP ${VERSION}." >&2
    fi
  else
    echo "Dockerfile for PHP ${VERSION} not found. Skipping..." >&2
  fi

  echo
done

echo "All builds and tests complete."
