# Changelog

## Unreleased (1.x)

### Fixed
- A report broken down by device has one `mobile` row: Matomo's smartphone, phablet and feature
  phone rows are merged, their counts summed (the bounce rate and the visit duration read from them
  weighted by visits), where each gave a row labelled `mobile`.
- A `device` filter takes the family's units: `mobile` is sent as Matomo's three mobile types ORed
  (`deviceType==smartphone,deviceType==phablet,deviceType==feature%20phone`), where Matomo refused
  `deviceType==mobile`; other values are sent lower-cased.
