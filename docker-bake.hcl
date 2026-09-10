# TaskWeaver build matrix — two published variants from ONE Dockerfile.
#
#   docker buildx bake                  # build controller + worker (no push)
#   docker buildx bake --push           # build and push the default group
#   docker buildx bake controller       # single target
#   docker buildx bake --print          # resolve and print, without building
#
# CI (develop.yaml / docker.yaml) invokes this with --file so the compose
# file that lives in the same directory is not merged in as extra targets.
#
# Naming contract (repo decision 2026-09-07):
#   DOCKERHUB_TARGET is the org/repo (Gitea Settings → Variables; value
#   digitaladapt/taskweaver). Tag suffixes are hardcoded here, not in CI:
#     controller → :TAG and (when VERSION is set) :VERSION
#     worker     → :TAG-worker and :VERSION-worker
#   CI sets TAG=latest + VERSION=<v-stripped> for tag pushes, TAG=develop
#   for pushes to main. Both amd64 and arm64 are always built (ARM server).
#
# Variables can be overridden by the environment, e.g.:
#   DOCKERHUB_TARGET=digitaladapt/taskweaver TAG=develop docker buildx bake --push

variable "DOCKERHUB_TARGET" {
  default = "digitaladapt/taskweaver"
  description = "Docker Hub repo/org (Gitea repo variable DOCKERHUB_TARGET)."
}

variable "TAG" {
  default = "latest"
  description = "Base tag for this build: latest (release), develop (main push), or a version."
}

variable "VERSION" {
  default = ""
  description = "Optional full version (v stripped) to also tag with; empty for develop builds."
}

group "default" {
  targets = ["controller", "worker"]
}

target "controller" {
  dockerfile = "Dockerfile"
  target     = "controller"
  context    = "."
  platforms  = ["linux/amd64", "linux/arm64"]
  tags = concat(
    ["${DOCKERHUB_TARGET}:${TAG}"],
    VERSION != "" ? ["${DOCKERHUB_TARGET}:${VERSION}"] : [],
  )
}

target "worker" {
  dockerfile = "Dockerfile"
  target     = "worker"
  context    = "."
  platforms  = ["linux/amd64", "linux/arm64"]
  tags = concat(
    ["${DOCKERHUB_TARGET}:${TAG}-worker"],
    VERSION != "" ? ["${DOCKERHUB_TARGET}:${VERSION}-worker"] : [],
  )
}
