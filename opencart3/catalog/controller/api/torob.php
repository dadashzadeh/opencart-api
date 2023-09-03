<?php
class ControllerApitorob extends Controller
{
    public function index()
    {
        // print_r('POST DATA'.$_POST);
        $this->load->language("api/cart");
        $this->load->model("catalog/product");
        $json = [];
        $json["products"] = [];
        if (isset($_GET["page"])) {
            $page = $_GET["page"];
            $limit = $_GET["limit"];
            $q_start = $limit * $page;
            if ($page == 1) {
                $q_start = 0;
            }
        } else {
            $page = 0;
            $limit = 100;
        }
        $filter_data = [
            "start" => $q_start,
            "limit" => $limit,
        ];
        $results = $this->model_catalog_product->getProducts($filter_data);
        foreach ($results as $result) {
            if (
                $this->customer->isLogged() ||
                !$this->config->get("config_customer_price")
            ) {
                $price = $this->tax->calculate(
                    $result["price"],
                    $result["tax_class_id"],
                    $this->config->get("config_tax")
                );
            } else {
                $price = false;
            }
            if ((float) $result["special"]) {
                $special = $this->tax->calculate(
                    $result["special"],
                    $result["tax_class_id"],
                    $this->config->get("config_tax")
                );
            } else {
                $special = false;
            }
            if ($this->config->get("config_tax")) {
                $tax = $this->currency->format(
                    (float) $result["special"]
                        ? $result["special"]
                        : $result["price"],
                    $this->session->data["currency"]
                );
            } else {
                $tax = false;
            }
            if ($this->config->get("config_review_status")) {
                $rating = (int) $result["rating"];
            } else {
                $rating = false;
            }

            if ($result["quantity"] <= 0) {
                $stock = "0";
            } else {
                $stock = "1";
            }

            $data["products"][] = [
                "product_id" => $result["product_id"],
                "name" => $result["name"],
                "model" => $result["model"],
                "sku" => $result["sku"],
                "price" => $price,
                "stock" => $stock,
                "special" => $special,
                "tax" => $tax,
                "rating" => $result["rating"],
                "href" => $this->url->link(
                    "product/product",
                    "product_id=" . $result["product_id"],
                    "SSL"
                ),
            ];
        }

        $json["products"] = $data["products"];
        $this->response->addHeader("Content-Type: application/json");
        $this->response->setOutput(json_encode($json));
    }
}
