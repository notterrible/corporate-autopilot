docroot=$1

is_wordpress_present() {
  local docroot=$1
  [[ (-e "$docroot/index.php" && -e "$docroot/wp-content") ||  (-e "$docroot/wp/index.php" && -e "$docroot/wp/wp-content") ]]
}

if ! is_wordpress_present "$docroot"; then
  echo "Skipping $binding_id, wordpress code not found"
  return
else
  echo "Found WP"
fi
