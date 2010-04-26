<?php
/*

Copyright (c) 2009, SilverStripe Australia PTY LTD - www.silverstripe.com.au
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the
      documentation and/or other materials provided with the distribution.
    * Neither the name of SilverStripe nor the names of its contributors may be used to endorse or promote products derived from this software
      without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE
GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT,
STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY
OF SUCH DAMAGE.
*/

/**
 * A Solr result set that provides access to results of a solr query, either as
 * a data object set, or as more specific solr items
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 */
class SolrResultSet
{
	/**
	 * @var SolrSearchService
	 */
	protected $solr;

	/**
	 * The raw result from Solr
	 *
	 * @var String
	 */
	protected $response;

	/**
	 * The actual decoded search result
	 *
	 * @var StdClass
	 */
	protected $result;

	/**
	 * The list of data objects that is represented by this search result set
	 *
	 * @var DataObjectSet
	 */
	protected $dataObjects;

	/**
	 * The query parameters that were used for the query
	 *
	 * @var StdClass
	 */
	protected $queryParameters;

    public function __construct($rawResponse, $parameters, SolrSearchService $service) {
		$this->response = $rawResponse;
		$this->queryParameters = $parameters;
		$this->solr = $service;
	}

	public function getErrors() {
		
	}

	/**
	 * Gets the raw result set as an object graph.
	 *
	 * This is effectively the results as sent from solre
	 */
	public function getResult() {
		if (!$this->result && $this->response->getHttpStatus() >= 200 && $this->response->getHttpStatus() < 300) {
			// decode the response
			$this->result = json_decode($this->response->getRawResponse());
		}

		return $this->result;
	}

	/**
	 * Return all the dataobjects that were found in this query
	 *
	 * @return DataObjectSet
	 */
	public function getDataObjects() {
		if (!$this->dataObjects) {
			$this->dataObjects = new DataObjectSet();

			$result = $this->getResult();
			$documents = $result->response;
			if ($documents && isset($documents->docs)) {
				$totalAdded = 0;
				foreach ($documents->docs as $doc) {
					list($type, $id) = explode('_', $doc->id);
					if (!$type || !$id) {
						ssau_log("Invalid solr document ID $doc->id", SS_Log::ERR);
						continue;
					}

					$object = DataObject::get_by_id($type, $id);
					if ($object && $object->ID) {
						// check that the user has permission
						if (isset($doc->score)) {
							$object->SearchScore = $doc->score;
						}

						$this->dataObjects->push($object);
						$totalAdded++;
					} else {
						ssau_log("Object $doc->id is no longer in the system, removing from index", SS_Log::ERR);
						$this->solr->unindex($type, $id);
					}
				}

				// update the dos with stats about this query
				$this->dataObjects->setPageLimits($documents->start, $this->queryParameters->limit, $documents->numFound);
			}

		}

		return $this->dataObjects;
	}

	/**
	 * Gets the details about facets found in this query
	 *
	 * @return array
	 *			An array of facet values in the format
	 *			array(
	 *				'field_name' => stdClass {
	 *					name,
	 *					count
	 *				}
	 *			)
	 */
	public function getFacets($type='fields') {
		$result = $this->getResult();
		if (!isset($result->facet_counts)) {
			return;
		}

		$n = 'facet_'.$type;

		$elems = $result->facet_counts->$n;
		
		$result = array();
		foreach ($elems as $field => $values) {
			$elemVals = array();
			foreach ($values as $vname => $vcount) {
				$r = new stdClass;
				$r->name = $vname;
				$r->count = $vcount;
				$elemVals[] = $r;
			}
			$result[$field] = $elemVals;
		}
		return $result;
	}
}
?>