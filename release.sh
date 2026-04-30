#!/bin/bash
# Release script for OctavaWMS WooCommerce connector (WordPress plugin)

function bump {
	version=${VERSION}
	search='("version":[[:space:]]*").+(")'
	replace="\1${version}\2"

	sed -i ".tmp" -E "s/${search}/${replace}/g" "$1"
	rm "$1.tmp"
}

function help {
	echo "Usage: $(basename $0) [<newversion>] [--remove-last] [--yes]"
	echo ""
	echo "Options:"
	echo "  <newversion>    Version number for the new release (e.g., 1.71.5). If omitted, the script bumps the last git tag."
	echo "  --remove-last   Remove the last release tag before creating new one (for re-release)"
	echo "  --yes           Skip interactive confirmation prompt"
	echo ""
	echo "This script will:"
	echo "  0. Show last git tag and ask for confirmation"
	echo "  1. Run composer check"
	echo "  2. Validate composer.json (strict)"
	echo "  3. Run tests"
	echo "  4. Merge to release/1.x branch"
	echo "  5. Create and push release tag"
	echo ""
	echo "If --remove-last is used, it will also:"
	echo "  - Remove the last release tag (locally and remotely)"
}

function ask_resume {
	echo ""
	echo "❌ $1 failed!"
	echo "Do you want to continue with the release process? (y/N)"
	read -r response
	if [[ ! "$response" =~ ^[Yy]$ ]]; then
		echo "Release cancelled."
		exit 1
	fi
}

function confirm_release {
	echo ""
	echo "🏷️  Last release tag: ${LAST_TAG:-<none>}"
	echo "📦 New release version: ${VERSION}"
	echo ""
	echo "Continue with the release process? (y/N)"
	read -r response
	if [[ ! "$response" =~ ^[Yy]$ ]]; then
		echo "Release cancelled."
		exit 1
	fi
}

function bump_semver {
	# Accepts tags like "1.2.3" or "v1.2.3"
	local raw="$1"
	local part="$2" # "patch" or "minor"
	local v="${raw#v}"

	if [[ ! "$v" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
		echo ""
		echo "❌ Can't auto-bump: last tag '$raw' is not a semver like 1.2.3"
		echo "Please provide an explicit version (e.g., $(basename $0) 1.2.3)"
		exit 1
	fi

	IFS='.' read -r major minor patch <<< "$v"
	if [ "$part" = "minor" ]; then
		minor=$((minor + 1))
		patch=0
	else
		patch=$((patch + 1))
	fi

	echo "${major}.${minor}.${patch}"
}

function choose_version {
	# Assumes LAST_TAG is set (may be empty)
	if [ -z "$LAST_TAG" ]; then
		if [ "$ASSUME_YES" = true ]; then
			echo ""
			echo "❌ No existing tags found; please provide a version explicitly."
			exit 1
		fi

		echo ""
		echo "🏷️  No existing tags found."
		echo "Enter initial release version (e.g., 1.0.0):"
		read -r VERSION
		if [ -z "$VERSION" ]; then
			echo "Release cancelled."
			exit 1
		fi
		return
	fi

	local next_patch
	local next_minor
	next_patch="$(bump_semver "$LAST_TAG" "patch")"
	next_minor="$(bump_semver "$LAST_TAG" "minor")"

	if [ "$ASSUME_YES" = true ]; then
		VERSION="$next_patch"
		return
	fi

	echo ""
	echo "🏷️  Last version: ${LAST_TAG}"
	echo "Default (patch bump): ${next_patch}"
	echo "Minor bump: ${next_minor}"
	echo ""
	echo "Choose bump: [Enter]=patch, m=minor, or type a custom version:"
	read -r response

	if [ -z "$response" ]; then
		VERSION="$next_patch"
		return
	fi

	if [[ "$response" =~ ^[Mm]$ ]]; then
		VERSION="$next_minor"
		return
	fi

	VERSION="$response"
}

function run_check {
	echo "🔍 Running composer check..."
	if ! composer check; then
		echo ""
		echo "❌ Composer check failed!"
		echo "Please fix issues before proceeding."
		exit 1
	fi
	echo "✅ Composer check passed."
}

function validate_composer {
	echo "✅ Validating composer.json (strict)..."
	if ! composer validate --strict; then
		echo ""
		echo "❌ Composer validation failed!"
		echo "Please fix composer.json issues before proceeding."
		exit 1
	fi
	echo "✅ Composer validation passed."
}

function run_tests {
	echo "🧪 Running tests..."
	if ! composer test; then
		ask_resume "Tests"
	fi
}

function remove_last_release {
	echo "🗑️  Removing last release tag..."
	
	# Get the last tag by version number, regardless of branch
	LAST_TAG=$(git tag --sort=-version:refname | head -1)
	
	if [ -z "$LAST_TAG" ]; then
		echo "⚠️  No previous tags found. Skipping tag removal."
		return
	fi
	
	echo "   Found last tag: $LAST_TAG"
	
	# Remove local tag
	if git tag -d "$LAST_TAG" 2>/dev/null; then
		echo "   ✅ Removed local tag: $LAST_TAG"
	else
		echo "   ⚠️  Local tag not found or already removed"
	fi
	
	# Remove remote tag
	if git push origin --delete "$LAST_TAG" 2>/dev/null; then
		echo "   ✅ Removed remote tag: $LAST_TAG"
	else
		echo "   ⚠️  Remote tag not found or already removed"
	fi
	
	echo "✅ Last release tag removed."
}

# Parse arguments
REMOVE_LAST=false
ASSUME_YES=false
VERSION=""

for arg in "$@"; do
	case $arg in
		--remove-last)
			REMOVE_LAST=true
			;;
		--yes|-y)
			ASSUME_YES=true
			;;
		help|--help|-h)
			help
			exit
			;;
		*)
			if [ -z "$VERSION" ]; then
				VERSION=$arg
			fi
			;;
	esac
done

DIR=`pwd -P`

cd "$DIR" || exit 1
git fetch --tags --quiet 2>/dev/null || true
# Get the latest tag by version number, regardless of branch
LAST_TAG=$(git tag --sort=-version:refname | head -1)

if [ -z "$VERSION" ]; then
	choose_version
fi

echo "🚀 Starting release process for version: $VERSION"
echo ""

if [ "$ASSUME_YES" != true ]; then
	confirm_release
fi

cd "$DIR" && git checkout main && git pull

# Run quality checks
run_check

# Validate composer.json before merging
validate_composer

run_tests

echo "🔄 Merging to release/1.x branch..."
# Check if branch exists locally or remotely
if git show-ref --verify --quiet refs/heads/release/1.x; then
	# Branch exists locally
	cd "$DIR" && git checkout release/1.x && git pull
elif git show-ref --verify --quiet refs/remotes/origin/release/1.x; then
	# Branch exists remotely but not locally
	cd "$DIR" && git checkout -b release/1.x origin/release/1.x
else
	# Branch doesn't exist, create it from main
	echo "   Creating release/1.x branch from main..."
	cd "$DIR" && git checkout -b release/1.x
	cd "$DIR" && git push -u origin release/1.x
fi
cd "$DIR" && git merge main -m "Bump to ${VERSION}."
cd "$DIR" && git push

# Remove last release tag if requested
if [ "$REMOVE_LAST" = true ]; then
	remove_last_release
fi

echo "🏷️  Creating and pushing release tag from release/1.x branch..."
cd "$DIR" && git tag -a "${VERSION}" -m "${VERSION}"
cd "$DIR" && git push --tags

echo "🔄 Returning to main branch..."
cd "$DIR" && git checkout main && git pull

echo ""
echo "🎉 Release ${VERSION} completed successfully!"
echo "✅ Composer check passed"
echo "✅ Composer validation passed"
echo "✅ Tests passed"
echo "✅ Merged to release/1.x branch"
echo "✅ Release tag created and pushed"
